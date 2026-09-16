<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lines a thread keeps about itself.
 *
 * Renaming a conversation is private - only the person who did it sees the
 * name - so both clients used to note it in their own local storage and draw
 * "You renamed the conversation to X" from there. That made the line a
 * property of a browser or a phone rather than of the account: renaming on
 * the website left the app showing nothing, and reinstalling lost the lot.
 *
 * The row is still per-person, which is what keeps the rename private; it is
 * just kept where the account can reach it from anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_events')) {
            return;
        }

        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id();

            // Whose thread this line belongs to, and which thread: an item
            // and the other person, the same way conversations are keyed.
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('other_user_id');

            $table->string('kind', 32)->default('renamed');

            // Null is a name that was taken away, which the clients render as
            // "You removed the conversation name".
            $table->string('name', 80)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'item_id', 'other_user_id'], 'conversation_events_thread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_events');
    }
};
