<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionPresenter;
use App\Models\Item;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\PhotoUploader;
use App\Support\LoyaltyRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Buyer-facing checkout.
 *
 * The client sends only the item, how many points the buyer wants to spend,
 * and the payment method. Prices, discounts, totals and reward figures are all
 * recalculated here from the database - nothing financial is taken from the
 * request, and the buyer's identity comes from the Sanctum token rather than
 * any field in the body.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PhotoUploader $uploader,
    ) {
    }

    /**
     * The checkout breakdown, before committing.
     * GET /api/checkout/quote?item_id=12&points_used=2
     *
     * Returns the same numbers the real checkout will use, so the screen the
     * buyer confirms cannot disagree with what gets charged.
     */
    public function quote(Request $request)
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,item_id'],
            'points_used' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = Item::where('item_id', $validated['item_id'])->first();
        $buyer = $request->user();

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        if ($item->seller_id === $buyer->user_id) {
            return response()->json(['message' => 'You cannot buy your own item.'], 403);
        }

        if (!$item->isPurchasable()) {
            return response()->json(['message' => 'This item is not available for purchase.'], 409);
        }

        $quote = $this->checkout->quote($item, $buyer, (int) ($validated['points_used'] ?? 0));

        return response()->json([
            'message' => 'Checkout quote',
            'data' => [
                'item_id' => $item->item_id,
                'item_title' => $item->title,

                // The complete breakdown the checkout screen must display.
                'item_price' => $quote['item_price']->toDecimalString(),
                'item_price_formatted' => '₱' . $quote['item_price']->toFormattedString(),
                'available_points' => $quote['available_points'],
                'points_used' => $quote['points_used'],
                'points_discount' => $quote['points_discount']->toDecimalString(),
                'points_discount_formatted' => '₱' . $quote['points_discount']->toFormattedString(),
                'amount_due' => $quote['amount_due']->toDecimalString(),
                'amount_due_formatted' => '₱' . $quote['amount_due']->toFormattedString(),

                'max_usable_points' => $quote['max_usable_points'],
                'points_redemption_value' => LoyaltyRules::PESOS_PER_REDEEMED_POINT,
                'reward_points_on_completion' => $quote['reward_points'],
                'is_full_points_checkout' => $quote['is_full_points'],
                'payment_required' => !$quote['is_full_points'],
                'payment_methods' => $quote['is_full_points']
                    ? []
                    : [Transaction::METHOD_CASH, Transaction::METHOD_GCASH],
            ],
        ], 200);
    }

    /**
     * Start a checkout. Holds the item and takes any redeemed points.
     * POST /api/checkout
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,item_id'],
            'points_used' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['required', 'in:cash,gcash'],
        ]);

        $item = Item::where('item_id', $validated['item_id'])->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        try {
            $transaction = $this->checkout->checkout(
                $item,
                $request->user(),
                (int) ($validated['points_used'] ?? 0),
                $validated['payment_method'],
            );

            $transaction->load(['item.photos', 'buyer', 'seller']);

            return response()->json([
                'message' => $transaction->isFullPointsCheckout()
                    ? 'Checkout complete - your points covered the full amount.'
                    : 'Checkout created. Please settle the remaining amount.',
                'data' => TransactionPresenter::forBuyer($transaction),
                'wallet_points' => $request->user()->fresh()->wallet_points,
            ], 201);

        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            Log::error('Checkout failed', [
                'item_id' => $validated['item_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Checkout could not be completed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload a GCash proof of payment.
     * POST /api/checkout/{transaction_id}/payment-proof
     *
     * There is no live GCash gateway: the buyer uploads a receipt and Admin
     * verifies it by hand.
     */
    public function uploadPaymentProof(Request $request, $transactionId)
    {
        $request->validate(['proof' => ['required', 'file']]);

        $transaction = Transaction::where('transaction_id', $transactionId)->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        // Ownership comes from the token, not from anything in the body.
        if ($transaction->buyer_id !== $request->user()->user_id) {
            return response()->json(['message' => 'This is not your order.'], 403);
        }

        $file = $request->file('proof');

        if ($error = $this->uploader->validateImages([$file], PhotoUploader::ALLOWED_PROOF_MIME_TYPES)) {
            return response()->json($error, 422);
        }

        try {
            $url = $this->uploader->upload($file, 'payment_proofs');

            if ($url === null) {
                return response()->json(['message' => 'Failed to upload the payment proof.'], 500);
            }

            $transaction = $this->checkout->attachPaymentProof($transaction, $url);
            $transaction->load(['item.photos']);

            return response()->json([
                'message' => 'Payment proof submitted. It is now awaiting admin verification.',
                'data' => TransactionPresenter::forBuyer($transaction),
            ], 200);

        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            Log::error('Payment proof upload failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to submit the payment proof'], 500);
        }
    }

    /**
     * A buyer abandons their own unpaid checkout.
     * POST /api/checkout/{transaction_id}/cancel
     */
    public function cancel(Request $request, $transactionId)
    {
        $transaction = Transaction::where('transaction_id', $transactionId)->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($transaction->buyer_id !== $request->user()->user_id) {
            return response()->json(['message' => 'This is not your order.'], 403);
        }

        // Once payment is verified only Admin may unwind the order.
        if ($transaction->payment_status === Transaction::PAYMENT_VERIFIED) {
            return response()->json([
                'message' => 'Your payment has already been verified. Please contact the admin to cancel.',
            ], 409);
        }

        try {
            $transaction = $this->checkout->cancel(
                $transaction,
                $request->user(),
                'Cancelled by the buyer'
            );

            return response()->json([
                'message' => 'Checkout cancelled. Any points you used have been returned.',
                'data' => TransactionPresenter::forBuyer($transaction->load('item.photos')),
                'wallet_points' => $request->user()->fresh()->wallet_points,
            ], 200);

        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
