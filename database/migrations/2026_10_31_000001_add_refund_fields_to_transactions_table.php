<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'paymongo_payment_id')) {
                $table->string('paymongo_payment_id', 255)->nullable()->after('paymongo_event_id');
            }
            if (!Schema::hasColumn('transactions', 'refund_status')) {
                $table->string('refund_status', 32)->nullable()->after('paymongo_payment_id');
            }
            if (!Schema::hasColumn('transactions', 'refund_amount')) {
                $table->decimal('refund_amount', 10, 2)->nullable()->after('refund_status');
            }
            if (!Schema::hasColumn('transactions', 'refund_reason')) {
                $table->string('refund_reason', 500)->nullable()->after('refund_amount');
            }
            if (!Schema::hasColumn('transactions', 'refund_reference')) {
                $table->string('refund_reference', 255)->nullable()->after('refund_reason');
            }
            if (!Schema::hasColumn('transactions', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('refund_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            foreach ([
                'paymongo_payment_id',
                'refund_status',
                'refund_amount',
                'refund_reason',
                'refund_reference',
                'refunded_at',
            ] as $col) {
                if (Schema::hasColumn('transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

