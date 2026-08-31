<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seller-side turnover now carries proof and reminders.
 *
 * When Admin scans a seller's turnover QR at the counter, they photograph the
 * item being received and the seller being paid - two pictures, stored here
 * beside the turnover they document. `meetup_reminders_sent` records which
 * before-the-meetup reminders (6h / 1h / 30m) have already been pushed to the
 * seller, so the scheduler never sends the same one twice.
 *
 * Guarded like the rest of the marketplace migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'turnover_photo')) {
                $table->string('turnover_photo', 512)->nullable()->after('turnover_notes');
            }
            if (!Schema::hasColumn('items', 'seller_payout_photo')) {
                $table->string('seller_payout_photo', 512)->nullable()->after('turnover_photo');
            }
            if (!Schema::hasColumn('items', 'meetup_reminders_sent')) {
                $table->string('meetup_reminders_sent', 32)->nullable()->after('meetup_schedule');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            foreach (['turnover_photo', 'seller_payout_photo', 'meetup_reminders_sent'] as $column) {
                if (Schema::hasColumn('items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
