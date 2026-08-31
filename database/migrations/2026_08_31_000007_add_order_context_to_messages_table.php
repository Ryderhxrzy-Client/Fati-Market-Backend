<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order notices posted into a chat used to be plain sentences, which left the
 * apps with nothing to render but a paragraph and no way to tell an order
 * apart from something the buyer typed.
 *
 * `kind` marks what a message is, and `transaction_id` ties it to the order it
 * describes, so a client can draw a real order card - item, photo, payment
 * method, payment status - and Admin can act on it in place.
 *
 * Guarded like the rest of the marketplace migrations, so it is a no-op where
 * the columns already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'kind')) {
                // 'text' is what every existing row is.
                $table->string('kind', 32)->default('text')->after('message');
            }
            if (!Schema::hasColumn('messages', 'transaction_id')) {
                $table->unsignedBigInteger('transaction_id')->nullable()->after('kind');
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index('transaction_id', 'messages_transaction_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_transaction_id_index');

            foreach (['kind', 'transaction_id'] as $column) {
                if (Schema::hasColumn('messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
