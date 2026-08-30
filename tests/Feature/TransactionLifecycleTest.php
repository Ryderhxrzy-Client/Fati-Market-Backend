<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Point;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sections 6, 7 and 8: payment verification, the points ledger, idempotent
 * completion and admin transaction management.
 */
class TransactionLifecycleTest extends MarketplaceTestCase
{
    /** Open a checkout and return [transaction, buyer, item]. */
    private function openCheckout(
        string $publicPrice = '250',
        int $points = 0,
        int $pointsUsed = 0,
        string $method = 'cash',
    ): array {
        $item = $this->publishedItem($publicPrice, '180');
        $buyer = $this->student($points);

        $response = $this->actingAs($buyer)->postJson('/api/checkout', [
            'item_id' => $item->item_id,
            'points_used' => $pointsUsed,
            'payment_method' => $method,
        ])->assertStatus(201);

        return [
            Transaction::find($response->json('data.transaction_id')),
            $buyer,
            $item,
        ];
    }

    // ── GCash proof ──────────────────────────────────────────────────────

    #[Test]
    public function a_gcash_proof_must_be_uploaded_and_then_verified_by_an_admin(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout('250', 0, 0, 'gcash');

        $this->assertSame(Transaction::STATUS_PENDING_PAYMENT, $transaction->status);

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', Transaction::PAYMENT_PROOF_SUBMITTED)
            ->assertJsonPath('data.status', Transaction::STATUS_PAYMENT_PROOF_SUBMITTED);

        // It cannot be completed while the payment is unverified.
        $this->actingAs($this->admin())
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete")
            ->assertStatus(409);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment")
            ->assertOk()
            ->assertJsonPath('data.payment_status', Transaction::PAYMENT_VERIFIED);
    }

    #[Test]
    public function a_payment_proof_must_be_an_image_or_pdf_within_the_size_limit(): void
    {
        [$transaction, $buyer] = $this->openCheckout('250', 0, 0, 'gcash');

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->create('proof.exe', 10, 'application/x-msdownload'),
            ])
            ->assertStatus(422);

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->create('proof.jpg', 6000, 'image/jpeg'),
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function another_buyer_cannot_upload_a_proof_against_someone_elses_order(): void
    {
        [$transaction] = $this->openCheckout('250', 0, 0, 'gcash');

        $this->actingAs($this->student())
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function a_rejected_payment_proof_restores_the_points_and_releases_the_item(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout('250', 5, 5, 'gcash');

        $this->assertSame(0, $buyer->fresh()->wallet_points);
        $this->assertSame(Item::STATUS_RESERVED, $item->fresh()->status);

        $this->actingAs($buyer)->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
            'proof' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertOk();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/reject-payment", [
                'reason' => 'The receipt does not match the amount due.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Transaction::STATUS_REJECTED);

        // Points come back, with a reversal entry on the ledger.
        $this->assertSame(5, $buyer->fresh()->wallet_points);
        $this->assertSame(Item::STATUS_PUBLIC, $item->fresh()->status);
        $this->assertDatabaseHas('points', [
            'user_id' => $buyer->user_id,
            'transaction_id' => $transaction->transaction_id,
            'type' => Point::TYPE_REFUND,
            'points_change' => 5,
        ]);
    }

    // ── Completion and rewards ───────────────────────────────────────────

    #[Test]
    public function completing_a_transaction_credits_the_reward_exactly_once(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout('250', 0, 0, 'cash');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment")
            ->assertOk();

        // No reward before completion.
        $this->assertSame(0, $buyer->fresh()->wallet_points);

        $this->actingAs($admin)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete")
            ->assertOk()
            ->assertJsonPath('reward_points_credited', 2)
            ->assertJsonPath('buyer_wallet_points', 2);

        $this->assertSame(2, $buyer->fresh()->wallet_points);
        $this->assertSame(Item::STATUS_SOLD, $item->fresh()->status);
        $this->assertSame(1, Point::where('type', Point::TYPE_REWARD)->count());
    }

    #[Test]
    public function repeating_the_completion_request_does_not_duplicate_the_points(): void
    {
        [$transaction, $buyer] = $this->openCheckout('250', 0, 0, 'cash');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment");

        // Five identical requests, as a double-tapped button or a retry would
        // produce.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)
                ->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete")
                ->assertOk();
        }

        $this->assertSame(2, $buyer->fresh()->wallet_points);
        $this->assertSame(1, Point::where('type', Point::TYPE_REWARD)->count());
        $this->assertSame(1, Transaction::where('status', Transaction::STATUS_COMPLETED)->count());
    }

    #[Test]
    public function every_wallet_change_is_mirrored_by_a_ledger_entry_with_a_running_balance(): void
    {
        [$transaction, $buyer] = $this->openCheckout('250', 10, 4, 'cash');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment");
        $this->actingAs($admin)->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete");

        $entries = Point::where('user_id', $buyer->user_id)->orderBy('point_id')->get();

        $this->assertCount(2, $entries);

        // Redemption: 10 -> 6
        $this->assertSame(Point::TYPE_REDEEM, $entries[0]->type);
        $this->assertSame(-4, $entries[0]->points_change);
        $this->assertSame(6, $entries[0]->balance_after);
        $this->assertSame($transaction->transaction_id, $entries[0]->transaction_id);

        // Reward: 6 -> 8
        $this->assertSame(Point::TYPE_REWARD, $entries[1]->type);
        $this->assertSame(2, $entries[1]->points_change);
        $this->assertSame(8, $entries[1]->balance_after);

        $this->assertSame(8, $buyer->fresh()->wallet_points);
    }

    #[Test]
    public function only_an_admin_can_complete_or_cancel_a_transaction(): void
    {
        [$transaction, $buyer] = $this->openCheckout('250', 0, 0, 'cash');

        $this->actingAs($buyer)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete")
            ->assertStatus(403);

        $this->actingAs($buyer)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/cancel", ['reason' => 'nope'])
            ->assertStatus(403);

        $this->actingAs($buyer)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment")
            ->assertStatus(403);
    }

    #[Test]
    public function a_cancelled_transaction_restores_the_points_and_republishes_the_item(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout('250', 6, 6, 'cash');

        $this->assertSame(0, $buyer->fresh()->wallet_points);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/cancel", [
                'reason' => 'Buyer did not show up.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Transaction::STATUS_CANCELLED)
            ->assertJsonPath('data.cancel_reason', 'Buyer did not show up.');

        $this->assertSame(6, $buyer->fresh()->wallet_points);
        $this->assertSame(Item::STATUS_PUBLIC, $item->fresh()->status);
    }

    #[Test]
    public function a_completed_transaction_cannot_be_cancelled(): void
    {
        [$transaction] = $this->openCheckout('250', 0, 0, 'cash');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment");
        $this->actingAs($admin)->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete");

        $this->actingAs($admin)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/cancel", ['reason' => 'oops'])
            ->assertStatus(409);
    }

    #[Test]
    public function the_full_pickup_lifecycle_runs_through_to_completion(): void
    {
        [$transaction] = $this->openCheckout('250', 0, 0, 'cash');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment")
            ->assertOk()
            ->assertJsonPath('data.status', Transaction::STATUS_RESERVED);

        $this->actingAs($admin)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/ready-for-pickup")
            ->assertOk()
            ->assertJsonPath('data.status', Transaction::STATUS_READY_FOR_PICKUP)
            ->assertJsonPath('data.pickup_status', Transaction::PICKUP_READY);

        $this->actingAs($admin)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Transaction::STATUS_COMPLETED)
            ->assertJsonPath('data.pickup_status', Transaction::PICKUP_PICKED_UP);
    }

    // ── Admin transaction screen ─────────────────────────────────────────

    #[Test]
    public function the_admin_transaction_screen_exposes_the_full_breakdown(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout('250', 5, 2, 'gcash');

        $this->actingAs($buyer)->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
            'proof' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertOk();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/transactions')
            ->assertOk();

        $row = $response->json('data.0');

        $this->assertSame($buyer->user_id, $row['buyer']['user_id']);
        $this->assertSame($item->item_id, $row['item']['item_id']);
        $this->assertSame('250.00', $row['subtotal']);
        $this->assertSame(2, $row['points_used']);
        $this->assertSame('10.00', $row['points_discount_amount']);
        $this->assertSame('240.00', $row['amount_due']);
        $this->assertSame(Transaction::METHOD_GCASH, $row['payment_method']);
        $this->assertNotNull($row['payment_proof']);
        $this->assertSame(Transaction::PAYMENT_PROOF_SUBMITTED, $row['payment_status']);
        $this->assertSame(Transaction::PICKUP_NOT_READY, $row['pickup_status']);
        $this->assertSame(2, $row['reward_points_to_credit']);
        $this->assertFalse($row['reward_points_credited']);

        $this->assertContains('verify_payment', $row['available_actions']);
        $this->assertContains('reject_payment', $row['available_actions']);
    }

    #[Test]
    public function seller_payouts_never_appear_as_buyer_orders(): void
    {
        // A legacy payout row, as the old "Send Points & Finalize" flow wrote.
        $item = $this->publishedItem('250', '180');

        Transaction::create([
            'item_id' => $item->item_id,
            'buyer_id' => $this->admin()->user_id,
            'seller_id' => $item->seller_id,
            'payment_method' => 'points',
            'points_used' => 200,
            'status' => Transaction::STATUS_COMPLETED,
            'is_seller_payout' => true,
            'transaction_date' => now(),
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/transactions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_admin_send_points_tool_is_idempotent_and_writes_a_ledger_entry(): void
    {
        $student = $this->student(0);
        $admin = $this->admin();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin)
                ->postJson('/api/admin/send-points', [
                    'user_id' => $student->user_id,
                    'points' => 5,
                    'reason' => 'Goodwill bonus',
                    'idempotency_key' => 'bonus-2026-08-31-a',
                ])
                ->assertOk();
        }

        $this->assertSame(5, $student->fresh()->wallet_points);
        $this->assertSame(1, Point::where('idempotency_key', 'bonus-2026-08-31-a')->count());
    }

    #[Test]
    public function a_wallet_balance_never_moves_without_a_ledger_entry(): void
    {
        [$transaction, $buyer] = $this->openCheckout('250', 8, 3, 'cash');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment");
        $this->actingAs($admin)->postJson("/api/admin/transactions/{$transaction->transaction_id}/complete");

        // Replaying the ledger from zero must land on the stored balance.
        $replayed = Point::where('user_id', $buyer->user_id)->sum('points_change');

        $this->assertSame(8 + $replayed, $buyer->fresh()->wallet_points);
        $this->assertSame(
            $buyer->fresh()->wallet_points,
            Point::where('user_id', $buyer->user_id)->orderByDesc('point_id')->first()->balance_after,
        );
    }
}
