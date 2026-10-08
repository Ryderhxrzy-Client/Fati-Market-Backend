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

        // Idempotency check: verify if event was already processed or payment is already verified
        if ($transaction->payment_status === Transaction::PAYMENT_VERIFIED || $transaction->paymongo_event_id === $eventId) {
            Log::info("PayMongo Webhook: Event [{$eventId}] already processed for Transaction #{$transaction->transaction_id}.");
            return response()->json(['message' => 'Webhook event already processed.'], 200);
        }

        try {
            DB::transaction(function () use ($transaction, $checkoutSessionId, $eventId, $paidAmountInCentavos) {
                $locked = Transaction::where('transaction_id', $transaction->transaction_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Re-verify idempotency inside DB lock
                if ($locked->payment_status === Transaction::PAYMENT_VERIFIED || $locked->paymongo_event_id === $eventId) {
                    return;
                }

                if ($paidAmountInCentavos !== null) {
                    $expectedCentavos = (int) round($locked->amount_due * 100);
                    if ($paidAmountInCentavos < $expectedCentavos) {
                        Log::warning("PayMongo Webhook: Paid amount ({$paidAmountInCentavos} centavos) is less than amount due ({$expectedCentavos} centavos) for Transaction #{$locked->transaction_id}.");
                    }
                }

                $newStatus = in_array($locked->status, [
                    Transaction::STATUS_PENDING_PAYMENT,
                    Transaction::STATUS_PAYMENT_PROOF_SUBMITTED,
                ], true) ? Transaction::STATUS_RESERVED : $locked->status;

                $locked->update([
                    'payment_status' => Transaction::PAYMENT_VERIFIED,
                    'payment_verified_at' => now(),
                    'payment_method' => Transaction::METHOD_PAYMONGO,
                    'payment_reference' => $checkoutSessionId ?? $locked->payment_reference,
                    'paymongo_checkout_session_id' => $checkoutSessionId ?? $locked->paymongo_checkout_session_id,
                    'paymongo_event_id' => $eventId,
                    'status' => $newStatus,
                ]);

                $fresh = $locked->fresh();

                // Send chat notification to buyer and item thread
                $item = Item::where('item_id', $fresh->item_id)->first();
                $buyer = User::where('user_id', $fresh->buyer_id)->first();

                if ($item !== null && $buyer !== null) {
                    $this->notifier->outcome(
                        $fresh,
                        $item,
                        '✅ Your online payment via PayMongo has been verified. The item is reserved for you.'
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

