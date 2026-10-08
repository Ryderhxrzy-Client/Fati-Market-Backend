<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class PayMongoIntegrationTest extends MarketplaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.paymongo.mode', 'test');
        Config::set('services.paymongo.secret_key_test', 'sk_test_mock_secret_key');
        Config::set('services.paymongo.public_key_test', 'pk_test_mock_public_key');
        Config::set('services.paymongo.webhook_link', 'https://example.com/api/paymongo/webhook');
        Config::set('services.paymongo.webhook_secret_key_test', 'whsk_test_mock_secret');
    }

    public function test_buyer_can_create_paymongo_checkout_session()
    {
        $buyer = $this->student();
        $item = $this->publishedItem('500.00');

        $transaction = Transaction::create([
            'item_id' => $item->item_id,
            'buyer_id' => $buyer->user_id,
            'seller_id' => $item->seller_id,
            'subtotal' => '500.00',
            'amount_due' => '500.00',
            'payment_method' => 'cash',
            'payment_status' => Transaction::PAYMENT_UNPAID,
            'status' => Transaction::STATUS_PENDING_PAYMENT,
        ]);

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_test_session_123',
                    'type' => 'checkout_session',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_test_session_123',
                        'reference_number' => (string) $transaction->transaction_id,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/paymongo/checkout', [
                'transaction_id' => $transaction->transaction_id,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'PayMongo checkout session created successfully.',
                'data' => [
                    'checkout_session_id' => 'cs_test_session_123',
                    'checkout_url' => 'https://checkout.paymongo.com/cs_test_session_123',
                    'transaction_id' => $transaction->transaction_id,
                ],
            ]);

        $transaction->refresh();
        $this->assertEquals(Transaction::METHOD_PAYMONGO, $transaction->payment_method);
        $this->assertEquals('cs_test_session_123', $transaction->paymongo_checkout_session_id);
    }

    public function test_valid_webhook_verifies_payment_and_updates_order()
    {
        $buyer = $this->student();
        $item = $this->publishedItem('300.00');

        $transaction = Transaction::create([
            'item_id' => $item->item_id,
            'buyer_id' => $buyer->user_id,
            'seller_id' => $item->seller_id,
            'subtotal' => '300.00',
            'amount_due' => '300.00',
            'payment_method' => Transaction::METHOD_PAYMONGO,
            'payment_status' => Transaction::PAYMENT_UNPAID,
            'status' => Transaction::STATUS_PENDING_PAYMENT,
            'paymongo_checkout_session_id' => 'cs_test_session_999',
        ]);

        $timestamp = time();
        $payload = [
            'data' => [
                'id' => 'evt_test_paid_001',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => 'cs_test_session_999',
                        'type' => 'checkout_session',
                        'attributes' => [
                            'reference_number' => (string) $transaction->transaction_id,
                            'amount' => 30000,
                        ],
                    ],
                ],
            ],
        ];

        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, 'whsk_test_mock_secret');
        $signatureHeader = "t={$timestamp},te={$signature}";

        $response = $this->call(
            'POST',
            '/api/paymongo/webhook',
            [],
            [],
            [],
            [
                'HTTP_PAYMONGO_SIGNATURE' => $signatureHeader,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody
        );

        $response->assertStatus(200)
            ->assertJson(['message' => 'Payment processed and verified successfully.']);

        $transaction->refresh();
        $this->assertEquals(Transaction::PAYMENT_VERIFIED, $transaction->payment_status);
        $this->assertEquals(Transaction::STATUS_RESERVED, $transaction->status);
        $this->assertEquals('evt_test_paid_001', $transaction->paymongo_event_id);
    }

    public function test_webhook_rejects_invalid_signature()
    {
        $payload = ['data' => ['id' => 'evt_123']];
        $rawBody = json_encode($payload);
        $invalidHeader = 't=' . time() . ',te=invalid_signature_hash';

        $response = $this->call(
            'POST',
            '/api/paymongo/webhook',
            [],
            [],
            [],
            ['HTTP_PAYMONGO_SIGNATURE' => $invalidHeader],
            $rawBody
        );

        $response->assertStatus(400)
            ->assertJson(['error' => 'Invalid webhook signature.']);
    }

    public function test_webhook_is_idempotent()
    {
        $buyer = $this->student();
        $item = $this->publishedItem('150.00');

        $transaction = Transaction::create([
            'item_id' => $item->item_id,
            'buyer_id' => $buyer->user_id,
            'seller_id' => $item->seller_id,
            'subtotal' => '150.00',
            'amount_due' => '150.00',
            'payment_method' => Transaction::METHOD_PAYMONGO,
            'payment_status' => Transaction::PAYMENT_VERIFIED,
            'status' => Transaction::STATUS_RESERVED,
            'paymongo_event_id' => 'evt_test_already_processed',
        ]);

        $timestamp = time();
        $payload = [
            'data' => [
                'id' => 'evt_test_already_processed',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => 'cs_test_999',
                        'attributes' => [
                            'reference_number' => (string) $transaction->transaction_id,
                        ],
                    ],
                ],
            ],
        ];

        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, 'whsk_test_mock_secret');

        $response = $this->call(
            'POST',
            '/api/paymongo/webhook',
            [],
            [],
            [],
            ['HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature}"],
            $rawBody
        );

        $response->assertStatus(200)
            ->assertJson(['message' => 'Webhook event already processed.']);
    }

    public function test_admin_can_trigger_webhook_setup()
    {
        $admin = $this->admin();

        Http::fake([
            'https://api.paymongo.com/v1/webhooks' => Http::sequence()
                ->push(['data' => []], 200)
                ->push([
                    'data' => [
                        'id' => 'hook_new_123',
                        'attributes' => [
                            'url' => 'https://example.com/api/paymongo/webhook',
                            'secret_key' => 'whsk_test_new_secret_key',
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/paymongo/webhook/setup');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'created',
                'webhook_id' => 'hook_new_123',
                'secret_key' => 'whsk_test_new_secret_key',
            ]);
    }
}
