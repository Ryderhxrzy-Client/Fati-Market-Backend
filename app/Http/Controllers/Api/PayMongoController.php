<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\PayMongoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PayMongoController extends Controller
{
    public function __construct(
        private readonly PayMongoService $payMongoService,
    ) {
    }

    /**
     * Create a PayMongo Checkout Session for an existing order/transaction.
     * POST /api/paymongo/checkout
     */
    public function createCheckoutSession(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => ['required', 'integer', 'exists:transactions,transaction_id'],
            'success_url' => ['nullable', 'url', 'max:500'],
            'cancel_url' => ['nullable', 'url', 'max:500'],
        ]);

        $transaction = Transaction::where('transaction_id', $validated['transaction_id'])->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        if ($transaction->buyer_id !== $request->user()->user_id) {
            return response()->json(['message' => 'This is not your order.'], 403);
        }

        if ($transaction->isTerminal()) {
            return response()->json(['message' => 'This order is already closed.'], 409);
        }

        if ($transaction->payment_status === Transaction::PAYMENT_VERIFIED) {
            return response()->json(['message' => 'Payment for this order has already been verified.'], 409);
        }

        if ($transaction->payment_method === Transaction::METHOD_CASH
            && $transaction->downpayment_status === 'verified') {
            return response()->json(['message' => 'The 50% downpayment for this order is already paid.'], 409);
        }

        if ($transaction->amount_due <= 0) {
            return response()->json(['message' => 'This order does not require payment.'], 400);
        }

        try {
            $session = $this->payMongoService->createCheckoutSession(
                $transaction,
                $validated['success_url'] ?? null,
                $validated['cancel_url'] ?? null
            );

            $isCashDownpayment = $transaction->payment_method === Transaction::METHOD_CASH
                && $transaction->downpayment_status !== 'verified'
                && (float) $transaction->downpayment_amount > 0;
            $chargedAmount = $isCashDownpayment
                ? $transaction->downpayment_amount
                : $transaction->amount_due;

            $transaction->update([
                // A 50% deposit does not turn the remaining cash-on-pickup
                // balance into a fully-online order.
                'payment_method' => $isCashDownpayment
                    ? Transaction::METHOD_CASH
                    : Transaction::METHOD_PAYMONGO,
                'payment_reference' => $session['id'],
                'paymongo_checkout_session_id' => $session['id'],
            ]);

            return response()->json([
                'message' => 'PayMongo checkout session created successfully.',
                'data' => [
                    'checkout_session_id' => $session['id'],
                    'checkout_url' => $session['checkout_url'],
                    'transaction_id' => $transaction->transaction_id,
                    'amount_due' => $chargedAmount,
                    'payment_type' => $isCashDownpayment ? 'downpayment' : 'full_payment',
                ],
            ], 201);

        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Failed to create PayMongo checkout session', [
                'transaction_id' => $validated['transaction_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not create checkout session with PayMongo. Please try again later.',
            ], 500);
        }
    }
}

