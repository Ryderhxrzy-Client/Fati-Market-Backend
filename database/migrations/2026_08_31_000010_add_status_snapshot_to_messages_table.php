<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order card recorded no state of its own, so it rendered the order's
 * CURRENT state - and every card in a thread therefore said the same thing.
 * A buyer who paid saw "checking payment" twice: once on the card announcing
 * the order, once on the card announcing the receipt.
 *
 * A conversation is a history. These columns freeze what was true when each
 * message was posted, so the thread reads: waiting for payment, then receipt
 * sent, then verified - each line keeping its own moment.
 *
 * Guarded like the rest of the marketplace migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'payment_status_at')) {
                $table->string('payment_status_at', 32)->nullable()->after('transaction_id');
            }
            if (!Schema::hasColumn('messages', 'order_status_at')) {
                $table->string('order_status_at', 32)->nullable()->after('payment_status_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            foreach (['payment_status_at', 'order_status_at'] as $column) {
                if (Schema::hasColumn('messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
