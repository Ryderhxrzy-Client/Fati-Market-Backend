<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'paymongo_checkout_session_id')) {
                $table->string('paymongo_checkout_session_id', 255)->nullable()->after('payment_reference');
            }
            if (!Schema::hasColumn('transactions', 'paymongo_event_id')) {
                $table->string('paymongo_event_id', 255)->nullable()->after('paymongo_checkout_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'paymongo_checkout_session_id')) {
                $table->dropColumn('paymongo_checkout_session_id');
            }
            if (Schema::hasColumn('transactions', 'paymongo_event_id')) {
                $table->dropColumn('paymongo_event_id');
            }
        });
    }
};

