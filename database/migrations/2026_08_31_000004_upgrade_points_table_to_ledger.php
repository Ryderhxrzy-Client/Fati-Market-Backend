<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the existing `points` table into a proper append-only ledger.
 *
 * The table already recorded user_id / points_change / reason / related_item_id,
 * so it is extended in place rather than replaced - there must be exactly one
 * points system.
 *
 * `idempotency_key` is the safety net for the completion flow: a unique index
 * means a replayed "complete transaction" request can never credit rewards or
 * deduct points twice, enforced by the database rather than by application
 * logic alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('points', function (Blueprint $table) {
            if (!Schema::hasColumn('points', 'transaction_id')) {
                $table->unsignedBigInteger('transaction_id')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('points', 'item_id')) {
                // `related_item_id` is kept as-is for backward compatibility;
                // `item_id` is the name the new code and API use.
                $table->unsignedBigInteger('item_id')->nullable()->after('transaction_id');
            }
            if (!Schema::hasColumn('points', 'balance_after')) {
                $table->integer('balance_after')->nullable()->after('points_change');
            }
            if (!Schema::hasColumn('points', 'type')) {
                // redeem | reward | refund | payout_legacy | adjustment
                $table->string('type', 32)->default('adjustment')->after('balance_after');
            }
            if (!Schema::hasColumn('points', 'idempotency_key')) {
                $table->string('idempotency_key', 191)->nullable()->after('reason');
            }
            if (!Schema::hasColumn('points', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `points` MODIFY `reason` VARCHAR(64) NOT NULL DEFAULT 'adjustment'");
        }

        Schema::table('points', function (Blueprint $table) {
            $table->unique('idempotency_key', 'points_idempotency_key_unique');
            $table->index('transaction_id', 'points_transaction_id_index');
            $table->index('item_id', 'points_item_id_index');
            $table->index('type', 'points_type_index');
        });

        // Backfill the new item_id column from the legacy related_item_id so
        // both names describe the same rows.
        DB::table('points')
            ->whereNull('item_id')
            ->whereNotNull('related_item_id')
            ->update(['item_id' => DB::raw('related_item_id')]);
    }

    public function down(): void
    {
        Schema::table('points', function (Blueprint $table) {
            $table->dropUnique('points_idempotency_key_unique');
            $table->dropIndex('points_transaction_id_index');
            $table->dropIndex('points_item_id_index');
            $table->dropIndex('points_type_index');

            foreach (['transaction_id', 'item_id', 'balance_after', 'type', 'idempotency_key', 'updated_at'] as $column) {
                if (Schema::hasColumn('points', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
