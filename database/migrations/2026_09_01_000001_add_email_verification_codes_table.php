<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email verification by one-time code, replacing the uploaded document.
 *
 * Registration used to mean photographing a student ID or registration card and
 * then waiting for an admin to look at it. Proving the school address is the
 * same proof - only that address can receive the code, and only students have
 * one - and it costs nobody an approval queue.
 *
 * Guarded throughout, because the production database predates migrations and
 * may already carry `email_verified_at` from Laravel's own users table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            });
        }

        if (!Schema::hasTable('email_verification_codes')) {
            Schema::create('email_verification_codes', function (Blueprint $table) {
                // One live code per address: asking for another replaces it,
                // so an old email can never be used after a new one is sent.
                $table->string('email')->primary();
                // Hashed, like every other credential here - a leaked row must
                // not be a way into somebody's account.
                $table->string('code');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');

        // `email_verified_at` is deliberately left alone: it may have been
        // there before this migration, and dropping it would discard the
        // record of who has proven their address.
    }
};
