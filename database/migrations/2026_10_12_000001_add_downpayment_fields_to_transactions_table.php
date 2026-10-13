<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'downpayment_amount')) {
                $table->decimal('downpayment_amount', 10, 2)->default(0)->after('amount_due');
            }
            if (!Schema::hasColumn('transactions', 'remaining_balance')) {
                $table->decimal('remaining_balance', 10, 2)->default(0)->after('downpayment_amount');
            }
            if (!Schema::hasColumn('transactions', 'downpayment_status')) {
                $table->string('downpayment_status', 32)->default('unpaid')->after('remaining_balance');
            }
            if (!Schema::hasColumn('transactions', 'downpayment_proof')) {
                $table->string('downpayment_proof', 512)->nullable()->after('downpayment_status');
            }
            if (!Schema::hasColumn('transactions', 'downpayment_verified_at')) {
                $table->timestamp('downpayment_verified_at')->nullable()->after('downpayment_proof');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            foreach ([
                'downpayment_amount',
                'remaining_balance',
                'downpayment_status',
                'downpayment_proof',
                'downpayment_verified_at',
            ] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

