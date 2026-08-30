<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baseline for the marketplace tables.
 *
 * These tables were created directly against the production MySQL database
 * before the project used migrations, so nothing in database/migrations
 * described them. That made the schema impossible to rebuild for tests or on
 * a fresh developer machine.
 *
 * This migration captures the pre-existing shape only - it deliberately does
 * not introduce any of the cash/rewards columns, which land in the follow-up
 * migrations. Every create is guarded by hasTable() so running this against
 * production is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->bigIncrements('category_id');
                $table->string('name');
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('items')) {
            Schema::create('items', function (Blueprint $table) {
                $table->bigIncrements('item_id');
                $table->unsignedBigInteger('seller_id');
                $table->string('title');
                $table->text('description')->nullable();
                $table->unsignedBigInteger('category_id')->nullable();
                // Stored as a plain string rather than a MySQL ENUM so that new
                // statuses can be added without an ALTER that SQLite cannot run.
                $table->string('status', 32)->default('private');
                $table->integer('price_points')->default(0);
                $table->integer('markup_points')->default(0);
                $table->timestamps();

                $table->index('seller_id');
                $table->index('category_id');
                $table->index('status');
            });
        }

        if (!Schema::hasTable('item_photos')) {
            Schema::create('item_photos', function (Blueprint $table) {
                $table->bigIncrements('photo_id');
                $table->unsignedBigInteger('item_id');
                $table->string('photo_url', 512);

                $table->index('item_id');
            });
        }

        if (!Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->bigIncrements('message_id');
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('sender_id');
                $table->unsignedBigInteger('receiver_id');
                $table->text('message');
                $table->timestamp('sent_at')->nullable();
                $table->boolean('is_read')->default(false);

                $table->index('item_id');
                $table->index('sender_id');
                $table->index('receiver_id');
            });
        }

        if (!Schema::hasTable('points')) {
            Schema::create('points', function (Blueprint $table) {
                $table->bigIncrements('point_id');
                $table->unsignedBigInteger('user_id');
                $table->integer('points_change');
                $table->string('reason', 32);
                $table->unsignedBigInteger('related_item_id')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index('user_id');
                $table->index('related_item_id');
            });
        }

        if (!Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->bigIncrements('transaction_id');
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('buyer_id');
                $table->unsignedBigInteger('seller_id')->nullable();
                $table->string('payment_method', 32)->default('points');
                $table->integer('points_used')->default(0);
                $table->string('status', 32)->default('reserved');
                $table->timestamp('transaction_date')->useCurrent();

                $table->index('item_id');
                $table->index('buyer_id');
                $table->index('seller_id');
            });
        }

        if (!Schema::hasTable('favorites')) {
            Schema::create('favorites', function (Blueprint $table) {
                $table->bigIncrements('favorite_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('item_id');

                $table->unique(['user_id', 'item_id']);
            });
        }

        if (!Schema::hasTable('reservations')) {
            Schema::create('reservations', function (Blueprint $table) {
                $table->bigIncrements('reservation_id');
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('user_id');
                $table->string('status', 32)->default('active');
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index('item_id');
                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('student_information')) {
            Schema::create('student_information', function (Blueprint $table) {
                $table->bigIncrements('student_information_id');
                $table->unsignedBigInteger('user_id');
                $table->string('first_name');
                $table->string('last_name');
                $table->string('profile_picture', 512)->nullable();
                $table->timestamps();

                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('student_verification')) {
            Schema::create('student_verification', function (Blueprint $table) {
                $table->bigIncrements('student_verification_id');
                $table->unsignedBigInteger('user_id');
                $table->string('verification_use', 32);
                $table->string('link', 512)->nullable();
                $table->boolean('is_verified')->default(false);
                $table->string('status', 32)->default('pending');
                $table->string('reason')->nullable();
                $table->timestamps();

                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: this migration only baselines pre-existing
        // tables. Dropping them would destroy production data that no
        // migration ever created.
    }
};
