<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash becomes the official item price; points become a loyalty reward.
 *
 * Money is stored as DECIMAL(10,2) - never a float. All arithmetic happens in
 * integer centavos in PHP (see App\Support\Money).
 *
 * The three prices are kept strictly separate, because the old `markup_points`
 * column was overloaded: it acted as the public price in the catalog *and* as
 * the profit figure in the admin reports. Those are different numbers now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'seller_asking_price')) {
                // What the student seller asked for, in pesos.
                $table->decimal('seller_asking_price', 10, 2)->nullable()->after('description');
            }
            if (!Schema::hasColumn('items', 'acquisition_price')) {
                // What Admin actually agreed to pay the seller after negotiation.
                $table->decimal('acquisition_price', 10, 2)->nullable()->after('seller_asking_price');
            }
            if (!Schema::hasColumn('items', 'public_price')) {
                // What a buyer pays in the public catalog.
                $table->decimal('public_price', 10, 2)->nullable()->after('acquisition_price');
            }
            if (!Schema::hasColumn('items', 'reward_points')) {
                // floor(public_price / 100), recomputed server-side on publish.
                $table->unsignedInteger('reward_points')->default(0)->after('public_price');
            }
            if (!Schema::hasColumn('items', 'seller_payout_status')) {
                // Seller cash payout is tracked separately from buyer rewards.
                $table->string('seller_payout_status', 32)->default('unpaid')->after('reward_points');
            }
            if (!Schema::hasColumn('items', 'seller_payout_amount')) {
                $table->decimal('seller_payout_amount', 10, 2)->nullable()->after('seller_payout_status');
            }
            if (!Schema::hasColumn('items', 'seller_paid_at')) {
                $table->timestamp('seller_paid_at')->nullable()->after('seller_payout_amount');
            }
            if (!Schema::hasColumn('items', 'seller_paid_by')) {
                $table->unsignedBigInteger('seller_paid_by')->nullable()->after('seller_paid_at');
            }
            if (!Schema::hasColumn('items', 'acquired_at')) {
                // Physical turnover: when Ofelia/Admin received and verified it.
                $table->timestamp('acquired_at')->nullable()->after('seller_paid_by');
            }
            if (!Schema::hasColumn('items', 'acquired_by')) {
                $table->unsignedBigInteger('acquired_by')->nullable()->after('acquired_at');
            }
            if (!Schema::hasColumn('items', 'turnover_notes')) {
                $table->string('turnover_notes', 500)->nullable()->after('acquired_by');
            }
            if (!Schema::hasColumn('items', 'meetup_schedule')) {
                // Agreed meeting / physical turnover schedule from the chat.
                $table->timestamp('meetup_schedule')->nullable()->after('turnover_notes');
            }
            if (!Schema::hasColumn('items', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('meetup_schedule');
            }
            if (!Schema::hasColumn('items', 'published_by')) {
                $table->unsignedBigInteger('published_by')->nullable()->after('published_at');
            }
            if (!Schema::hasColumn('items', 'price_source')) {
                // 'cash' for anything priced under the new rules, 'legacy_points'
                // for rows whose peso price was derived from the old point value.
                $table->string('price_source', 32)->default('cash')->after('published_by');
            }
            if (!Schema::hasColumn('items', 'rejected_reason')) {
                $table->string('rejected_reason', 500)->nullable()->after('price_source');
            }
        });

        // The production `items.status` column is a MySQL ENUM that has no
        // 'pending' / 'rejected' member. Widen it to VARCHAR so the lifecycle
        // can grow without another ALTER, and so it matches the SQLite shape
        // used by the test suite.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `items` MODIFY `status` VARCHAR(32) NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            foreach ([
                'seller_asking_price', 'acquisition_price', 'public_price', 'reward_points',
                'seller_payout_status', 'seller_payout_amount', 'seller_paid_at', 'seller_paid_by',
                'acquired_at', 'acquired_by', 'turnover_notes', 'meetup_schedule',
                'published_at', 'published_by', 'price_source', 'rejected_reason',
            ] as $column) {
                if (Schema::hasColumn('items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
