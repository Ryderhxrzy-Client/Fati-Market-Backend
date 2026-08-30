<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The transactions table previously carried no money at all - only
 * `points_used`. Checkout now needs the full cash breakdown, a GCash
 * proof-of-payment, and separate payment / pickup lifecycles.
 *
 * `is_seller_payout` marks the legacy rows that the old "Send Points &
 * Finalize" flow wrote into this table. Those are seller payouts, not buyer
 * orders, and must never surface in the buyer transaction screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'subtotal')) {
                // The item's public price at the moment of checkout.
                $table->decimal('subtotal', 10, 2)->default(0)->after('seller_id');
            }
            if (!Schema::hasColumn('transactions', 'points_discount_amount')) {
                // points_used * 5 pesos, recalculated server-side.
                $table->decimal('points_discount_amount', 10, 2)->default(0)->after('points_used');
            }
            if (!Schema::hasColumn('transactions', 'amount_due')) {
                // max(subtotal - points_discount_amount, 0)
                $table->decimal('amount_due', 10, 2)->default(0)->after('points_discount_amount');
            }
            if (!Schema::hasColumn('transactions', 'reward_points_earned')) {
                $table->unsignedInteger('reward_points_earned')->default(0)->after('amount_due');
            }
            if (!Schema::hasColumn('transactions', 'payment_proof')) {
                $table->string('payment_proof', 512)->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('transactions', 'payment_proof_submitted_at')) {
                $table->timestamp('payment_proof_submitted_at')->nullable()->after('payment_proof');
            }
            if (!Schema::hasColumn('transactions', 'payment_status')) {
                $table->string('payment_status', 32)->default('unpaid')->after('payment_proof_submitted_at');
            }
            if (!Schema::hasColumn('transactions', 'payment_verified_at')) {
                $table->timestamp('payment_verified_at')->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('transactions', 'payment_verified_by')) {
                $table->unsignedBigInteger('payment_verified_by')->nullable()->after('payment_verified_at');
            }
            if (!Schema::hasColumn('transactions', 'pickup_status')) {
                $table->string('pickup_status', 32)->default('not_ready')->after('payment_verified_by');
            }
            if (!Schema::hasColumn('transactions', 'reserved_until')) {
                // Abandoned checkouts must not lock an item forever.
                $table->timestamp('reserved_until')->nullable()->after('pickup_status');
            }
            if (!Schema::hasColumn('transactions', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('reserved_until');
            }
            if (!Schema::hasColumn('transactions', 'completed_by')) {
                $table->unsignedBigInteger('completed_by')->nullable()->after('completed_at');
            }
            if (!Schema::hasColumn('transactions', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('completed_by');
            }
            if (!Schema::hasColumn('transactions', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            }
            if (!Schema::hasColumn('transactions', 'cancel_reason')) {
                $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');
            }
            if (!Schema::hasColumn('transactions', 'is_seller_payout')) {
                $table->boolean('is_seller_payout')->default(false)->after('cancel_reason');
            }
            if (!Schema::hasColumn('transactions', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('is_seller_payout');
            }
            if (!Schema::hasColumn('transactions', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });

        // Widen the ENUMs so the new lifecycle values fit. VARCHAR keeps MySQL
        // and the SQLite test schema identical.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `transactions` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT 'pending_payment'");
            DB::statement("ALTER TABLE `transactions` MODIFY `payment_method` VARCHAR(32) NOT NULL DEFAULT 'cash'");
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->index('status', 'transactions_status_index');
            $table->index('payment_status', 'transactions_payment_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_status_index');
            $table->dropIndex('transactions_payment_status_index');

            foreach ([
                'subtotal', 'points_discount_amount', 'amount_due', 'reward_points_earned',
                'payment_proof', 'payment_proof_submitted_at', 'payment_status',
                'payment_verified_at', 'payment_verified_by', 'pickup_status', 'reserved_until',
                'completed_at', 'completed_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
                'is_seller_payout', 'created_at', 'updated_at',
            ] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
