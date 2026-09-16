<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a chat has that this one lacked.
 *
 * A message can answer another one: `reply_to_message_id` points at the line
 * being quoted, the way Messenger draws a reply above the bubble. It is a
 * plain reference rather than a copy of the text, so a quote always shows
 * what was actually said.
 *
 * And a conversation can be pinned, archived, renamed or cleared - each of
 * those per person, because the store archiving a thread must not archive
 * it for the student. `conversation_settings` holds one row per person per
 * thread (a thread being an item and the other party). `cleared_at` is
 * "delete for me": everything before it stays hidden for that person, and
 * the thread reappears with only what arrives afterwards.
 *
 * Guarded like the rest of the marketplace migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'reply_to_message_id')) {
                $table->unsignedBigInteger('reply_to_message_id')->nullable()->after('order_status_at');
                $table->index('reply_to_message_id');
            }
        });

        if (!Schema::hasTable('conversation_settings')) {
            Schema::create('conversation_settings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('other_user_id');
                $table->string('custom_name', 80)->nullable();
                $table->boolean('is_pinned')->default(false);
                $table->timestamp('pinned_at')->nullable();
                $table->boolean('is_archived')->default(false);
                $table->timestamp('cleared_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'item_id', 'other_user_id'], 'conversation_settings_thread_unique');
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_settings');

        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'reply_to_message_id')) {
                $table->dropIndex(['reply_to_message_id']);
                $table->dropColumn('reply_to_message_id');
            }
        });
    }
};
