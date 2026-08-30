<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GCash is settled by hand: the buyer scans a static QR, pays, and submits the
 * proof. A screenshot alone is awkward for Admin to reconcile, so the GCash
 * reference number is captured alongside it.
 *
 * Guarded like the rest, so it is a no-op if the column already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'payment_reference')) {
                $table->string('payment_reference', 64)->nullable()->after('payment_proof');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'payment_reference')) {
                $table->dropColumn('payment_reference');
            }
        });
    }
};
