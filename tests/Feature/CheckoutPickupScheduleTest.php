<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The whole order flow at different times of day: the quote, the checkout
 * itself (cash and PayMongo), the pick-up date the server assigns, and the
 * buyer cancelling afterwards. Store hours: Mon-Sat, 8:00 AM to 6:00 PM.
 */
class CheckoutPickupScheduleTest extends MarketplaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Manila',
            'store.open_time' => '08:00',
            'store.close_time' => '18:00',
            'store.open_days' => '1,2,3,4,5,6',
            'services.paymongo.mode' => 'test',
            'services.paymongo.secret_key_test' => 'sk_test_mock_secret_key',
            'services.paymongo.public_key_test' => 'pk_test_mock_public_key',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function freeze(string $manilaTime): void
    {
        Carbon::setTestNow(Carbon::parse($manilaTime, 'Asia/Manila'));
    }

    /** @return array<string, array{string, string, bool}> now, assigned pick-up date, past cutoff */
    public static function moments(): array
    {
        // November 2026: Wed 4, Thu 5, Sat 7, Sun 8, Mon 9.
        return [
            'Wednesday 12:24 AM books today' => ['2026-11-04 00:24:00', '2026-11-04', false],
            'Wednesday 7:59 AM books today' => ['2026-11-04 07:59:59', '2026-11-04', false],
            'Wednesday 8:00 AM books today' => ['2026-11-04 08:00:00', '2026-11-04', false],
            'Wednesday 10:00 AM books today' => ['2026-11-04 10:00:00', '2026-11-04', false],
            'Wednesday 5:59 PM books today' => ['2026-11-04 17:59:59', '2026-11-04', false],
            'Wednesday 6:00 PM books tomorrow' => ['2026-11-04 18:00:00', '2026-11-05', true],
            'Wednesday 6:30 PM books tomorrow' => ['2026-11-04 18:30:00', '2026-11-05', true],
            'Saturday 7:00 PM books Monday' => ['2026-11-07 19:00:00', '2026-11-09', true],
            'Sunday 10:00 AM books Monday' => ['2026-11-08 10:00:00', '2026-11-09', true],
        ];
    }

    #[Test]
    #[DataProvider('moments')]
    public function a_cash_order_is_scheduled_on_the_first_day_the_store_can_take_it(string $now, string $pickupDate, bool $pastCutoff): void
    {
        $this->freeze($now);
        $item = $this->publishedItem('350', '200');
        $buyer = $this->student(0);

        $this->actingAs($buyer)
            ->getJson("/api/checkout/quote?item_id={$item->item_id}")
            ->assertOk()
            ->assertJsonPath('data.is_past_cutoff', $pastCutoff);

        $response = $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.pickup_date', $pickupDate)
            ->assertJsonPath('data.is_past_cutoff', $pastCutoff);

        $transaction = Transaction::findOrFail($response->json('data.transaction_id'));
        $this->assertSame($pickupDate, Carbon::parse($transaction->pickup_date)->toDateString());
        // The item is held until closing time of the pick-up day.
        $this->assertSame(
            "{$pickupDate} 18:00:00",
            Carbon::parse($transaction->reserved_until)->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'),
        );
        $this->assertSame('reserved', $item->fresh()->status);
    }

    #[Test]
    public function a_buyer_may_pick_today_before_the_store_opens(): void
    {
        $this->freeze('2026-11-04 00:24:00');
        $item = $this->publishedItem();
        $buyer = $this->student();

        $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'payment_method' => 'cash',
                'pickup_date' => '2026-11-04',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.pickup_date', '2026-11-04');
    }

    #[Test]
    public function a_buyer_may_pick_a_later_open_day(): void
    {
        $this->freeze('2026-11-04 00:24:00');
        $item = $this->publishedItem();
        $buyer = $this->student();

        $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'payment_method' => 'cash',
                'pickup_date' => '2026-11-06',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.pickup_date', '2026-11-06');
    }

    #[Test]
    public function today_is_refused_after_closing_with_the_next_open_day_named(): void
    {
        $this->freeze('2026-11-04 18:30:00');
        $item = $this->publishedItem();
        $buyer = $this->student();

        $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'payment_method' => 'cash',
                'pickup_date' => '2026-11-04',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Store is closed. Choose a pick-up/meet-up date from Thursday, Nov 5.');

        $this->assertSame('public', $item->fresh()->status);
    }

    #[Test]
    public function a_closed_day_is_refused(): void
    {
        $this->freeze('2026-11-04 10:00:00');
        $item = $this->publishedItem();
        $buyer = $this->student();

        $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'payment_method' => 'cash',
                'pickup_date' => '2026-11-08',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The store is closed on Sundays.');
    }

    #[Test]
    public function yesterday_is_refused_during_store_hours(): void
    {
        $this->freeze('2026-11-04 10:00:00');
        $item = $this->publishedItem();
        $buyer = $this->student();

        $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'payment_method' => 'cash',
                'pickup_date' => '2026-11-03',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That date has already passed. Pick today or a later open day.');
    }

    #[Test]
    public function a_paymongo_order_at_midnight_books_today_and_opens_a_session(): void
    {
        $this->freeze('2026-11-04 00:24:00');
        $item = $this->publishedItem('500', '300');
        $buyer = $this->student();

        $response = $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'payment_method' => 'paymongo',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.pickup_date', '2026-11-04');

        $transactionId = $response->json('data.transaction_id');

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_test_midnight',
                    'type' => 'checkout_session',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_test_midnight',
                        'reference_number' => (string) $transactionId,
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/paymongo/checkout', ['transaction_id' => $transactionId])
            ->assertStatus(201)
            ->assertJsonPath('data.checkout_session_id', 'cs_test_midnight');
    }

    #[Test]
    public function a_full_points_order_before_opening_is_reserved_for_today(): void
    {
        $this->freeze('2026-11-04 06:00:00');
        $item = $this->publishedItem('50', '20');
        $buyer = $this->student(50);

        $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'points_used' => 50,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.pickup_date', '2026-11-04')
            ->assertJsonPath('data.status', Transaction::STATUS_RESERVED);
    }

    #[Test]
    public function the_buyer_can_cancel_an_unpaid_order_placed_at_midnight(): void
    {
        $this->freeze('2026-11-04 00:24:00');
        $item = $this->publishedItem('250', '180');
        $buyer = $this->student(2);

        $transactionId = $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'points_used' => 2,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201)
            ->json('data.transaction_id');

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transactionId}/cancel")
            ->assertOk()
            ->assertJsonPath('wallet_points', 2);

        $this->assertSame('public', $item->fresh()->status);
        $this->assertSame(Transaction::STATUS_CANCELLED, Transaction::findOrFail($transactionId)->status);
    }
}
