<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: legacy point-priced listings become peso-priced listings.
 *
 * MIGRATION STRATEGY (explicit, not silent)
 * -----------------------------------------
 * Old rows priced items in points. Per the product decision, those point
 * values are converted directly into pesos at LEGACY_POINT_TO_PESO = 1, i.e.
 * an item that was 200 points becomes an item asking PHP 200.00.
 *
 * Every converted row is stamped `price_source = 'legacy_points'` so the
 * origin of the number is never lost and Admin can re-price later. Rows
 * created under the new rules carry `price_source = 'cash'`.
 *
 * Column mapping, which depends on how far the old item had progressed:
 *
 *   price_points  -> seller_asking_price   (always: what the student asked)
 *   price_points  -> acquisition_price     (only once Admin had taken it in;
 *                                           under the old flow Admin paid the
 *                                           seller exactly the asking points)
 *   markup_points -> public_price          (only for items that had reached
 *                                           the catalog - the old code served
 *                                           markup_points as the buyer-facing
 *                                           price for public/reserved/sold)
 *
 * NOTE ON markup_points: the old column meant "catalog price" in the buyer API
 * and "profit" in the admin reports. Only the catalog meaning is carried over
 * here. Profit is now derived (public_price - acquisition_price), so the
 * reports are recomputed rather than migrated.
 *
 * Status mapping: 'private' was the de-facto pending state, so it becomes
 * 'pending'. Later statuses keep their meaning.
 *
 * This migration is idempotent - it only touches rows whose peso columns are
 * still NULL.
 */
return new class extends Migration
{
    /** 1 legacy point == 1 peso. */
    private const LEGACY_POINT_TO_PESO = 1;

    public function up(): void
    {
        $rate = self::LEGACY_POINT_TO_PESO;
        $catalogStatuses = ['public', 'reserved', 'sold'];
        $acquiredStatuses = ['acquired', 'public', 'reserved', 'sold'];

        // ---- Items -------------------------------------------------------

        // Seller asking price: every legacy row had one.
        DB::table('items')
            ->whereNull('seller_asking_price')
            ->update([
                'seller_asking_price' => DB::raw("COALESCE(price_points, 0) * {$rate}"),
                'price_source' => 'legacy_points',
            ]);

        // Acquisition price: only for items Admin had already taken in. Under
        // the old flow the payout equalled the asking points exactly.
        DB::table('items')
            ->whereNull('acquisition_price')
            ->whereIn('status', $acquiredStatuses)
            ->update(['acquisition_price' => DB::raw("COALESCE(price_points, 0) * {$rate}")]);

        // Public price: only for items that actually reached the catalog.
        DB::table('items')
            ->whereNull('public_price')
            ->whereIn('status', $catalogStatuses)
            ->update(['public_price' => DB::raw("COALESCE(markup_points, 0) * {$rate}")]);

        // Reward points follow the same rule as new listings: floor(price/100).
        // Computed in PHP rather than SQL: FLOOR() is not available in every
        // SQLite build, and the centavo arithmetic must stay integer-exact.
        DB::table('items')
            ->whereNotNull('public_price')
            ->where('reward_points', 0)
            ->orderBy('item_id')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    $centavos = (int) round(((float) $item->public_price) * 100);

                    DB::table('items')
                        ->where('item_id', $item->item_id)
                        ->update(['reward_points' => intdiv($centavos, 10000)]);
                }
            }, 'item_id');

        // Turnover audit fields: these items were physically received under the
        // old flow, but nothing recorded when or by whom. Use updated_at as the
        // best available evidence and leave acquired_by NULL rather than
        // inventing a verifier.
        DB::table('items')
            ->whereNull('acquired_at')
            ->whereIn('status', $acquiredStatuses)
            ->update(['acquired_at' => DB::raw('updated_at')]);

        DB::table('items')
            ->whereNull('published_at')
            ->whereIn('status', $catalogStatuses)
            ->update(['published_at' => DB::raw('updated_at')]);

        // Seller payout: the old "Send Points & Finalize" wrote a points row
        // with reason 'sale' against the item. Those sellers were already paid.
        $paidItemIds = DB::table('points')
            ->where('reason', 'sale')
            ->whereNotNull('related_item_id')
            ->pluck('related_item_id')
            ->unique()
            ->all();

        if (!empty($paidItemIds)) {
            DB::table('items')
                ->whereIn('item_id', $paidItemIds)
                ->where('seller_payout_status', 'unpaid')
                ->update([
                    'seller_payout_status' => 'paid',
                    'seller_payout_amount' => DB::raw("COALESCE(acquisition_price, COALESCE(price_points, 0) * {$rate})"),
                ]);
        }

        // 'private' was the de-facto pending state.
        DB::table('items')->where('status', 'private')->update(['status' => 'pending']);

        // ---- Points ledger ----------------------------------------------

        // Classify existing ledger rows. Legacy types are deliberately distinct
        // from the new redeem/reward types so reward reporting can exclude the
        // old points-as-currency movements.
        foreach ([
            'purchase' => 'legacy_purchase',
            'sale' => 'legacy_payout',
            'markup' => 'legacy_markup',
            'bonus' => 'adjustment',
            'adjustment' => 'adjustment',
        ] as $reason => $type) {
            DB::table('points')
                ->where('reason', $reason)
                ->where('type', 'adjustment')
                ->whereNull('idempotency_key')
                ->update(['type' => $type]);
        }

        // balance_after is intentionally left NULL on legacy rows. It cannot be
        // reconstructed honestly: the old sendPoints flow mutated wallet
        // balances outside a database transaction, so replaying the ledger does
        // not reconcile with the stored wallet_points.

        // ---- Transactions -------------------------------------------------

        // Rows written by the old seller-payout flow have an admin as the
        // buyer. They are payouts, not buyer orders, and must be excluded from
        // the buyer transaction screens.
        $adminIds = DB::table('users')->where('role', 'admin')->pluck('user_id')->all();

        if (!empty($adminIds)) {
            DB::table('transactions')
                ->whereIn('buyer_id', $adminIds)
                ->update(['is_seller_payout' => true]);
        }

        // Legacy buyer purchases were paid entirely in points. Represent that
        // honestly as a full-points checkout with nothing due in cash, rather
        // than inventing cash that never changed hands.
        DB::table('transactions')
            ->where('is_seller_payout', false)
            ->where('payment_method', 'points')
            ->where('subtotal', 0)
            ->update([
                'subtotal' => DB::raw("COALESCE(points_used, 0) * {$rate}"),
                'points_discount_amount' => DB::raw("COALESCE(points_used, 0) * {$rate}"),
                'amount_due' => 0,
                'payment_method' => 'points_full',
            ]);

        // Map the old three-value status onto the new lifecycle.
        DB::table('transactions')->where('status', 'completed')->update([
            'payment_status' => 'verified',
            'pickup_status' => 'picked_up',
            'completed_at' => DB::raw('transaction_date'),
        ]);

        DB::table('transactions')->where('status', 'reserved')->update([
            'payment_status' => 'unpaid',
            'pickup_status' => 'not_ready',
        ]);

        DB::table('transactions')->where('status', 'cancelled')->update([
            'payment_status' => 'unpaid',
            'pickup_status' => 'not_ready',
            'cancelled_at' => DB::raw('transaction_date'),
        ]);

        DB::table('transactions')->whereNull('created_at')->update([
            'created_at' => DB::raw('transaction_date'),
            'updated_at' => DB::raw('transaction_date'),
        ]);
    }

    public function down(): void
    {
        // Not reversible: the source point values remain in price_points /
        // markup_points, so re-running up() reproduces the same result. Undoing
        // the conversion would mean deleting peso prices that Admin may since
        // have corrected by hand.
    }
};
