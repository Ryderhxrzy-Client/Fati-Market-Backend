<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayMongo API integration service for TEST/SANDBOX mode.
 */
class PayMongoService
{
    private const BASE_URL = 'https://api.paymongo.com/v1';

    public function getMode(): string
    {
        return config('services.paymongo.mode', 'test');
    }

    public function getSecretKey(): string
    {
        $mode = $this->getMode();
        $key = $mode === 'test'
            ? config('services.paymongo.secret_key_test')
            : config('services.paymongo.secret_key_live');

        if (empty($key)) {
            throw new RuntimeException("PayMongo secret key is not configured for mode [{$mode}].");
        }

        return $key;
    }

    public function getPublicKey(): ?string
    {
        $mode = $this->getMode();

        return $mode === 'test'
            ? config('services.paymongo.public_key_test')
            : config('services.paymongo.public_key_live');
    }

    public function getWebhookLink(): ?string
    {
        return config('services.paymongo.webhook_link');
    }

    public function getWebhookSecret(): ?string
    {
        $mode = $this->getMode();

        return $mode === 'test'
            ? config('services.paymongo.webhook_secret_key_test')
            : config('services.paymongo.webhook_secret_key_live');
    }

    /**
     * Create a PayMongo Checkout Session for a Transaction.
     *
     * @param Transaction $transaction
     * @param string|null $successUrl
     * @param string|null $cancelUrl
     * @return array{id: string, checkout_url: string, raw: array}
     */
    public function createCheckoutSession(
        Transaction $transaction,
        ?string $successUrl = null,
        ?string $cancelUrl = null
    ): array {
        $secretKey = $this->getSecretKey();
        $amountInCentavos = (int) round($transaction->amount_due * 100);

        if ($amountInCentavos <= 0) {
            throw new RuntimeException('Cannot create PayMongo checkout session for zero or negative amount.');
        }

        $itemTitle = $transaction->item?->title ?? "Order #{$transaction->transaction_id}";
        $description = "Payment for Order #{$transaction->transaction_id} - {$itemTitle}";

        $attributes = [
            'amount' => $amountInCentavos,
            'currency' => 'PHP',
            'description' => mb_substr($description, 0, 255),
            'line_items' => [
                [
                    'amount' => $amountInCentavos,
                    'currency' => 'PHP',
                    'name' => mb_substr($itemTitle, 0, 255),
                    'quantity' => 1,
                ],
            ],
            'payment_method_types' => [
                'gcash',
            ],
            'reference_number' => (string) $transaction->transaction_id,
            'send_email_receipt' => false,
            'show_description' => true,
            'show_line_items' => true,
        ];

        $defaultSuccessUrl = "https://ofelia.alertaraqc.com/payment/success?transaction_id={$transaction->transaction_id}";
        $defaultCancelUrl = "https://ofelia.alertaraqc.com/payment/cancel?transaction_id={$transaction->transaction_id}";

        if (!empty($successUrl)) {
            $attributes['success_url'] = str_replace('fati.alertaraqc.com', 'ofelia.alertaraqc.com', $successUrl);
        } else {
            $attributes['success_url'] = $defaultSuccessUrl;
        }

        if (!empty($cancelUrl)) {
            $attributes['cancel_url'] = str_replace('fati.alertaraqc.com', 'ofelia.alertaraqc.com', $cancelUrl);
        } else {
            $attributes['cancel_url'] = $defaultCancelUrl;
        }

        $payload = [
            'data' => [
                'attributes' => $attributes,
            ],
        ];

        $response = Http::withBasicAuth($secretKey, '')
            ->acceptJson()
            ->post(self::BASE_URL . '/checkout_sessions', $payload);

        if ($response->failed()) {
            Log::error('PayMongo Checkout Session creation failed', [
                'transaction_id' => $transaction->transaction_id,
                'status' => $response->status(),
                'error_detail' => $response->json('errors.0.detail') ?? $response->body(),
            ]);

            $errorMsg = $response->json('errors.0.detail') ?? 'Failed to create PayMongo checkout session.';
            throw new RuntimeException("PayMongo API error: {$errorMsg}");
        }

        $responseData = $response->json('data');
        $id = $responseData['id'] ?? null;
        $checkoutUrl = $responseData['attributes']['checkout_url'] ?? null;

        if (!$id || !$checkoutUrl) {
            throw new RuntimeException('PayMongo response missing checkout session ID or URL.');
        }

        return [
            'id' => $id,
            'checkout_url' => $checkoutUrl,
            'raw' => $responseData,
        ];
    }

    /**
     * List existing webhooks registered on PayMongo.
     *
     * @return array
     */
    public function listWebhooks(): array
    {
        $secretKey = $this->getSecretKey();

        $response = Http::withBasicAuth($secretKey, '')
            ->acceptJson()
            ->get(self::BASE_URL . '/webhooks');

        if ($response->failed()) {
            Log::error('PayMongo list webhooks failed', [
                'status' => $response->status(),
                'error_detail' => $response->json('errors.0.detail') ?? $response->body(),
            ]);
            $errorMsg = $response->json('errors.0.detail') ?? 'Failed to list webhooks.';
            throw new RuntimeException("PayMongo API error: {$errorMsg}");
        }

        return $response->json('data') ?? [];
    }

    /**
     * Create a new webhook on PayMongo.
     *
     * @param string $webhookUrl
     * @param array $events
     * @return array{id: string, secret_key: string|null, url: string, raw: array}
     */
    public function createWebhook(
        string $webhookUrl,
        array $events = ['checkout_session.payment.paid']
    ): array {
        $secretKey = $this->getSecretKey();

        $payload = [
            'data' => [
                'attributes' => [
                    'url' => $webhookUrl,
                    'events' => $events,
                ],
            ],
        ];

        $response = Http::withBasicAuth($secretKey, '')
            ->acceptJson()
            ->post(self::BASE_URL . '/webhooks', $payload);

        if ($response->failed()) {
            Log::error('PayMongo create webhook failed', [
                'status' => $response->status(),
                'error_detail' => $response->json('errors.0.detail') ?? $response->body(),
            ]);
            $errorMsg = $response->json('errors.0.detail') ?? 'Failed to create webhook.';
            throw new RuntimeException("PayMongo API error: {$errorMsg}");
        }

        $data = $response->json('data');
        $id = $data['id'] ?? null;
        $secret = $data['attributes']['secret_key'] ?? null;
        $url = $data['attributes']['url'] ?? $webhookUrl;

        return [
            'id' => $id,
            'secret_key' => $secret,
            'url' => $url,
            'raw' => $data,
        ];
    }

    /**
     * Get webhook information by ID.
     *
     * @param string $webhookId
     * @return array
     */
    public function getWebhook(string $webhookId): array
    {
        $secretKey = $this->getSecretKey();

        $response = Http::withBasicAuth($secretKey, '')
            ->acceptJson()
            ->get(self::BASE_URL . "/webhooks/{$webhookId}");

        if ($response->failed()) {
            Log::error('PayMongo get webhook failed', [
                'webhook_id' => $webhookId,
                'status' => $response->status(),
                'error_detail' => $response->json('errors.0.detail') ?? $response->body(),
            ]);
            $errorMsg = $response->json('errors.0.detail') ?? 'Failed to retrieve webhook.';
            throw new RuntimeException("PayMongo API error: {$errorMsg}");
        }

        return $response->json('data') ?? [];
    }

    /**
     * Protected setup mechanism: checks existing webhooks, reuses if matching, or creates a new one.
     *
     * @return array{status: string, message: string, secret_key: string|null, webhook_id: string|null, url: string}
     */
    public function setupWebhook(): array
    {
        $targetUrl = $this->getWebhookLink();

        if (empty($targetUrl)) {
            throw new RuntimeException('PAYMONGO_WEBHOOK_LINK is not set in environment or configuration.');
        }

        $existingWebhooks = $this->listWebhooks();

        foreach ($existingWebhooks as $wh) {
            $whAttributes = $wh['attributes'] ?? [];
            $whUrl = $whAttributes['url'] ?? '';
            $whEvents = $whAttributes['events'] ?? [];

            // Match URL (normalizing trailing slash) and verify required event subscription
            if (rtrim($whUrl, '/') === rtrim($targetUrl, '/') && in_array('checkout_session.payment.paid', $whEvents, true)) {
                return [
                    'status' => 'exists',
                    'message' => 'Matching PayMongo webhook already exists.',
                    'webhook_id' => $wh['id'] ?? null,
                    'secret_key' => null,
                    'url' => $whUrl,
                ];
            }
        }

        $newWebhook = $this->createWebhook($targetUrl, ['checkout_session.payment.paid']);

        return [
            'status' => 'created',
            'message' => 'PayMongo webhook registered successfully.',
            'webhook_id' => $newWebhook['id'],
            'secret_key' => $newWebhook['secret_key'],
            'url' => $newWebhook['url'],
        ];
    }

    /**
     * Verify PayMongo signature for webhook requests.
     *
     * Header format: "t=1600000000,te=signature_value"
     * Signature = HMAC SHA256 of "$timestamp.$rawBody" using PAYMONGO_WEBHOOK_SECRET_KEY_TEST.
     *
     * @param string $rawBody
     * @param string|null $signatureHeader
     * @param string|null $overrideSecret
     * @return bool
     */
    public function verifySignature(
        string $rawBody,
        ?string $signatureHeader,
        ?string $overrideSecret = null
    ): bool {
        if (empty($signatureHeader)) {
            Log::warning('PayMongo Webhook Verification Failed: Missing Paymongo-Signature header.');
            return false;
        }

        $secret = $overrideSecret ?? $this->getWebhookSecret();

        if (empty($secret)) {
            Log::error('PayMongo Webhook Verification Failed: Webhook secret key (PAYMONGO_WEBHOOK_SECRET_KEY_TEST) is not configured.');
            return false;
        }

        $headerData = [];
        $parts = explode(',', $signatureHeader);

        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $headerData[$kv[0]] = $kv[1];
            }
        }

        $timestamp = $headerData['t'] ?? null;
        $mode = $this->getMode();
        $signature = $mode === 'test' ? ($headerData['te'] ?? null) : ($headerData['li'] ?? null);

        if (empty($signature) && isset($headerData['te'])) {
            $signature = $headerData['te'];
        }

        if (empty($timestamp) || empty($signature)) {
            Log::warning('PayMongo Webhook Verification Failed: Missing timestamp or signature in header.', [
                'header' => $signatureHeader,
            ]);
            return false;
        }

        $stringToSign = $timestamp . '.' . $rawBody;
        $computedSignature = hash_hmac('sha256', $stringToSign, $secret);

        $isValid = hash_equals($computedSignature, $signature);

        if (!$isValid) {
            Log::warning('PayMongo Webhook Verification Failed: Signature mismatch.');
        }

        return $isValid;
    }
}

