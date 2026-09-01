<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address a student keeps after they graduate.
 *
 * A school account is lent, not owned: the day it is disabled, an account keyed
 * only to it becomes unreachable, and the points, orders and listings behind it
 * go with it. This column is the way back in - a personal address the student
 * links themselves, proven by a code, and usable to sign in once the school one
 * is gone.
 *
 * It is a credential, not a contact field, so it lives on `users` beside the
 * primary address and carries its own verified-at stamp. Unverified, it does
 * nothing at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'personal_email')) {
                // Unique, because it is a way in: two accounts sharing one
                // would mean one inbox opening either of them.
                $table->string('personal_email')->nullable()->unique()->after('email');
            }

            if (!Schema::hasColumn('users', 'personal_email_verified_at')) {
                $table->timestamp('personal_email_verified_at')
                    ->nullable()
                    ->after('personal_email');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['personal_email_verified_at', 'personal_email'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
