<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Support\OrderQr;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * The walk-in handover: the buyer shows their order QR, Admin scans it, takes
 * a photo of the handover, and completes the order - which is also the moment
 * reward points are credited.
 */
class WalkInPickupTest extends MarketplaceTestCase
{
    /** @return array{0: Transaction, 1: User} */
    private function paidOrder(bool $verified = true): array
    {
        $item = $this->publishedItem('250', '180');
        $buyer = $this->student();

        $response = $this->actingAs($buyer)->postJson('/api/checkout', [
            'item_id' => $item->item_id,
            'points_used' => 0,
            'payment_method' => 'gcash',
        ])->assertStatus(201);

        $transaction = Transaction::find($response->json('data.transaction_id'));

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
            ])->assertOk();

        if ($verified) {
            $this->actingAs($this->admin())
                ->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment")
                ->assertOk();
        }

        return [$transaction->fresh(), $buyer];
    }

    // ── The code itself ──────────────────────────────────────────────────

    #[Test]
    public function every_order_payload_carries_a_signed_qr_code(): void
    {
        [$transaction, $buyer] = $this->paidOrder();

        $row = collect($this->actingAs($buyer)->getJson('/api/transactions')->json('data'))
            ->firstWhere('transaction_id', $transaction->transaction_id);

        $this->assertSame(OrderQr::codeFor($transaction), $row['qr_code']);
        $this->assertSame(
            $transaction->transaction_id,
            OrderQr::transactionIdFrom($row['qr_code']),
        );
    }

    #[Test]
    public function a_tampered_code_resolves_to_nothing(): void
    {
        [$transaction] = $this->paidOrder();

        $code = OrderQr::codeFor($transaction);

        $this->assertNull(OrderQr::transactionIdFrom($code . 'x'));
        $this->assertNull(OrderQr::transactionIdFrom('FMQR1.999999.deadbeefdeadbeef'));
        $this->assertNull(OrderQr::transactionIdFrom(''));
        $this->assertNull(OrderQr::transactionIdFrom('hello world'));
    }

    // ── Scanning ─────────────────────────────────────────────────────────

    #[Test]
    public function scanning_a_valid_code_returns_the_order_with_its_actions(): void
    {
        [$transaction] = $this->paidOrder();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/transactions/scan?code=' . urlencode(OrderQr::codeFor($transaction)))
            ->assertOk();

        $this->assertSame($transaction->transaction_id, $response->json('data.transaction_id'));
        $this->assertSame('verified', $response->json('data.payment_status'));
        $this->assertContains('complete', $response->json('data.available_actions'));
    }

    #[Test]
    public function scanning_gibberish_is_a_404_and_scanning_is_admin_only(): void
    {
        [$transaction, $buyer] = $this->paidOrder();

        $this->actingAs($this->admin())
            ->getJson('/api/admin/transactions/scan?code=not-a-code')
            ->assertNotFound();

        $this->actingAs($buyer)
            ->getJson('/api/admin/transactions/scan?code=' . urlencode(OrderQr::codeFor($transaction)))
            ->assertStatus(403);
    }

    // ── Completing with the handover photo ───────────────────────────────

    #[Test]
    public function completing_with_a_photo_stores_it_and_credits_the_points(): void
    {
        [$transaction, $buyer] = $this->paidOrder();

        $response = $this->actingAs($this->admin())
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete", [
                'handover_photo' => UploadedFile::fake()->image('handover.jpg'),
            ])->assertOk();

        $this->assertSame('completed', $response->json('data.status'));
        $this->assertNotNull($response->json('data.handover_photo'));
        $this->assertSame(2, $response->json('reward_points_credited'));
        $this->assertSame(2, $buyer->fresh()->wallet_points);
    }

    #[Test]
    public function an_unverified_payment_cannot_be_completed_even_with_a_photo(): void
    {
        [$transaction] = $this->paidOrder(verified: false);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete", [
                'handover_photo' => UploadedFile::fake()->image('handover.jpg'),
            ])->assertStatus(409);

        $fresh = $transaction->fresh();
        $this->assertNotSame('completed', $fresh->status);
        $this->assertNull($fresh->handover_photo);
    }

    #[Test]
    public function completing_without_a_photo_still_works(): void
    {
        [$transaction] = $this->paidOrder();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    // ── The cash list means cash ─────────────────────────────────────────

    #[Test]
    public function the_cash_list_excludes_gcash_orders(): void
    {
        // A GCash order...
        [$gcash] = $this->paidOrder();

        // ...and a cash one.
        $item = $this->publishedItem('150', '100');
        $buyer = $this->student();
        $cashId = $this->actingAs($buyer)->postJson('/api/checkout', [
            'item_id' => $item->item_id,
            'points_used' => 0,
            'payment_method' => 'cash',
        ])->json('data.transaction_id');

        $rows = collect(
            $this->actingAs($this->admin())
                ->getJson('/api/admin/transactions/cash')
                ->assertOk()
                ->json('data')
        );

        $this->assertNotNull($rows->firstWhere('transaction_id', $cashId));
        $this->assertNull($rows->firstWhere('transaction_id', $gcash->transaction_id));
    }

    /**
     * The store account. Publishing an item already created an admin, and the
     * notifier addresses that one, so tests reuse it instead of minting more.
     */
    protected function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->orderBy('user_id')->firstOrFail();
    }
}
