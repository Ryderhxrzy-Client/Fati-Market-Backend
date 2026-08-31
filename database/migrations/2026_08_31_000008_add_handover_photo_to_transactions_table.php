<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in handovers now carry proof: when Admin completes an order at the
 * counter they photograph the buyer receiving the item, and that photo lives
 * on the transaction next to the GCash proof it mirrors.
 *
 * Guarded like the rest of the marketplace migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'handover_photo')) {
                $table->string('handover_photo', 512)->nullable()->after('payment_verified_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'handover_photo')) {
                $table->dropColumn('handover_photo');
            }
        });
    }
};
