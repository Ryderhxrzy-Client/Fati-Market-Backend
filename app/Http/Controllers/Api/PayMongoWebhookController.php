<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OrderChatNotifier;
use App\Services\PayMongoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PayMongoWebhookController extends Controller
{
    public function __construct(
        private readonly PayMongoService $payMongoService,
        private readonly OrderChatNotifier $notifier,
    ) {
    }

    /**
     * Public endpoint for receiving PayMongo webhooks.
     * POST /api/paymongo/webhook
     */
    public function handleWebhook(Request $request)
    {
        $rawBody = $request->getContent();
        $signatureHeader = $request->header('Paymongo-Signature') ?? $request->header('paymongo-signature');

        $webhookSecret = $this->payMongoService->getWebhookSecret();
        if (empty($webhookSecret)) {
            Log::error('PayMongo Webhook Error: Webhook secret key (PAYMONGO_WEBHOOK_SECRET_KEY_TEST) is not configured.');
            return response()->json(['error' => 'Webhook secret key is not configured.'], 500);
        }

        if (!$this->payMongoService->verifySignature($rawBody, $signatureHeader)) {
            return response()->json(['error' => 'Invalid webhook signature.'], 400);
        }

        $payload = json_decode($rawBody, true);
        if (!$payload || !isset($payload['data'])) {
            return response()->json(['error' => 'Invalid request payload.'], 400);
        }

        $eventId = $payload['data']['id'] ?? null;
        $eventType = $payload['data']['attributes']['type'] ?? null;

        if (in_array($eventType, ['payment.refunded', 'payment.refund.updated'], true)) {
            return $this->handleRefundEvent($payload, $eventId);
        }

        if ($eventType !== 'checkout_session.payment.paid') {
            return response()->json(['message' => "Event [{$eventType}] ignored."], 200);
        }

        $sessionData = $payload['data']['attributes']['data'] ?? [];
        $checkoutSessionId = $sessionData['id'] ?? null;
        $sessionAttributes = $sessionData['attributes'] ?? [];
        $referenceNumber = $sessionAttributes['reference_number'] ?? null;
        $paidAmountInCentavos = $sessionAttributes['amount'] ?? null;

        // Identify local transaction by reference_number (transaction_id) or session ID
        $transaction = null;
        if (!empty($referenceNumber) && is_numeric($referenceNumber)) {
            $transaction = Transaction::where('transaction_id', (int) $referenceNumber)->first();
        }

        if (!$transaction && !empty($checkoutSessionId)) {
            $transaction = Transaction::where('paymongo_checkout_session_id', $checkoutSessionId)
                ->orWhere('payment_reference', $checkoutSessionId)
                ->first();
        }

        if (!$transaction) {
            Log::warning('PayMongo Webhook: Transaction not found for received payment event.', [
                'event_id' => $eventId,
                'reference_number' => $referenceNumber,
                'checkout_session_id' => $checkoutSessionId,
            ]);

            return response()->json(['error' => 'Order not found.'], 404);
        }

        $cashDownpaymentPending = $transaction->payment_method === Transaction::METHOD_CASH
            && $transaction->downpayment_status !== 'verified'
            && (float) $transaction->downpayment_amount > 0;

        // A cash order can be payment-verified here while still retaining a
        // balance at pickup; all other verified orders are already settled.
        if ($transaction->paymongo_event_id === $eventId
            || (!$cashDownpaymentPending && $transaction->payment_status === Transaction::PAYMENT_VERIFIED)) {
            Log::info("PayMongo Webhook: Event [{$eventId}] already processed for Transaction #{$transaction->transaction_id}.");
            return response()->json(['message' => 'Webhook event already processed.'], 200);
        }

        try {
            DB::transaction(function () use ($transaction, $checkoutSessionId, $eventId, $paidAmountInCentavos) {
                $locked = Transaction::where('transaction_id', $transaction->transaction_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Re-verify idempotency inside DB lock
                $isCashDownpayment = $locked->payment_method === Transaction::METHOD_CASH
                    && $locked->downpayment_status !== 'verified'
                    && (float) $locked->downpayment_amount > 0;

                if ($locked->paymongo_event_id === $eventId
                    || (!$isCashDownpayment && $locked->payment_status === Transaction::PAYMENT_VERIFIED)) {
                    return;
                }

                if ($paidAmountInCentavos !== null) {
                    $expectedAmount = $isCashDownpayment
                        ? $locked->downpayment_amount
                        : $locked->amount_due;
                    $expectedCentavos = (int) round($expectedAmount * 100);
                    if ($paidAmountInCentavos < $expectedCentavos) {
                        Log::warning("PayMongo Webhook: Paid amount ({$paidAmountInCentavos} centavos) is less than amount due ({$expectedCentavos} centavos) for Transaction #{$locked->transaction_id}.");
                        throw new RuntimeException('PayMongo payment amount is below the required amount.');
                    }
                }

                $newStatus = in_array($locked->status, [
                    Transaction::STATUS_PENDING_PAYMENT,
                    Transaction::STATUS_PAYMENT_PROOF_SUBMITTED,
                ], true) ? Transaction::STATUS_RESERVED : $locked->status;

                $updates = [
                    'payment_status' => $isCashDownpayment
                        ? Transaction::PAYMENT_PARTIALLY_PAID
                        : Transaction::PAYMENT_VERIFIED,
                    'payment_verified_at' => $isCashDownpayment ? null : now(),
                    'payment_method' => $isCashDownpayment
                        ? Transaction::METHOD_CASH
                        : Transaction::METHOD_PAYMONGO,
                    'payment_reference' => $checkoutSessionId ?? $locked->payment_reference,
                    'paymongo_checkout_session_id' => $checkoutSessionId ?? $locked->paymongo_checkout_session_id,
                    'paymongo_event_id' => $eventId,
                    'status' => $newStatus,
                ];

                if ($isCashDownpayment) {
                    $updates['downpayment_status'] = 'verified';
                    $updates['downpayment_verified_at'] = now();
                }

                $sessionPayments = $sessionAttributes['payments'] ?? [];
                if (!empty($sessionPayments) && isset($sessionPayments[0]['id'])) {
                    $updates['paymongo_payment_id'] = $sessionPayments[0]['id'];
                }

                $locked->update($updates);

                $fresh = $locked->fresh();

                // Send chat notification to buyer and item thread
                $item = Item::where('item_id', $fresh->item_id)->first();
                $buyer = User::where('user_id', $fresh->buyer_id)->first();

                if ($item !== null && $buyer !== null) {
                    $this->notifier->outcome(
                        $fresh,
                        $item,
                        $isCashDownpayment
                            ? 'Partially paid: the 50% PayMongo downpayment was received. The remaining cash balance is unpaid and due at pickup.'
                            : 'Your online payment via PayMongo has been verified. The item is reserved for you.'
                    );
                }
            });

            return response()->json(['message' => 'Payment processed and verified successfully.'], 200);

        } catch (\Exception $e) {
            Log::error('PayMongo Webhook processing failed', [
                'transaction_id' => $transaction->transaction_id,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to process webhook payment event.'], 500);
        }
    }

    /**
     * Handle PayMongo refund events: payment.refunded, payment.refund.updated.
     */
    private function handleRefundEvent(array $payload, ?string $eventId)
    {
        $refundData = $payload['data']['attributes']['data'] ?? [];
        $resourceId = $refundData['id'] ?? null;
        $attributes = $refundData['attributes'] ?? [];

        $paymentId = $attributes['payment_id'] ?? (str_starts_with((string) $resourceId, 'pay_') ? $resourceId : null);
        $refundId = str_starts_with((string) $resourceId, 'ref_') ? $resourceId : ($attributes['refunds'][0]['id'] ?? null);
        $status = strtolower($attributes['status'] ?? '');
        $amountInCentavos = $attributes['amount'] ?? null;

        $transaction = null;
        if (!empty($paymentId)) {
            $transaction = Transaction::where('paymongo_payment_id', $paymentId)->first();
        }
        if (!$transaction && !empty($refundId)) {
            $transaction = Transaction::where('refund_reference', $refundId)->first();
        }

        if (!$transaction) {
            Log::warning('PayMongo Webhook: Transaction not found for refund event.', [
                'event_id' => $eventId,
                'payment_id' => $paymentId,
                'refund_id' => $refundId,
            ]);
            return response()->json(['message' => 'Transaction not found for refund.'], 200);
        }

        $refundAmountPesos = $amountInCentavos ? ($amountInCentavos / 100) : ($transaction->refund_amount ?? $transaction->amount_due);

        if (in_array($status, ['paid', 'succeeded', 'completed'], true) || $payload['data']['attributes']['type'] === 'payment.refunded') {
            $transaction->update([
                'refund_status' => Transaction::REFUND_COMPLETED,
                'refunded_at' => now(),
                'refund_reference' => $refundId ?? $transaction->refund_reference,
            ]);

            $item = Item::where('item_id', $transaction->item_id)->first();
            $buyer = User::where('user_id', $transaction->buyer_id)->first();
            if ($item !== null && $buyer !== null) {
                $formatted = number_format((float) $refundAmountPesos, 2);
                $this->notifier->outcome(
                    $transaction,
                    $item,
                    "Refund completed: ₱{$formatted} has been successfully refunded to your account via PayMongo."
                );
            }

            return response()->json(['message' => 'Refund event processed as completed.'], 200);
        }

        if (in_array($status, ['failed', 'rejected'], true)) {
            $transaction->update([
                'refund_status' => Transaction::REFUND_FAILED,
            ]);

            return response()->json(['message' => 'Refund event recorded as failed.'], 200);
        }

        $transaction->update([
            'refund_status' => Transaction::REFUND_PROCESSING,
            'refund_reference' => $refundId ?? $transaction->refund_reference,
        ]);

        return response()->json(['message' => 'Refund event updated.'], 200);
    }

    /**
     * Protected setup mechanism for registering webhook with PayMongo.
     * POST /api/paymongo/webhook/setup
     */
    public function setupWebhook(Request $request)
    {
        try {
            $result = $this->payMongoService->setupWebhook();

            $response = [
                'message' => $result['message'],
                'status' => $result['status'],
                'webhook_id' => $result['webhook_id'],
                'url' => $result['url'],
            ];

            if (!empty($result['secret_key'])) {
                $response['secret_key'] = $result['secret_key'];
                $response['instructions'] = 'Place this generated secret key in your .env file as PAYMONGO_WEBHOOK_SECRET_KEY_TEST=' . $result['secret_key'];
            }

            return response()->json($response, 200);

        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('PayMongo webhook setup failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to register webhook with PayMongo.'], 500);
        }
    }
}

