<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings a freshly-migrated `users` table in line with the live one.
 *
 * The production database predates Laravel migrations: its `users` table uses
 * `user_id` as the primary key and carries `wallet_points`, `role` and
 * `is_active`, which is what App\Models\User has always expected. The stock
 * 0001_01_01_000000_create_users_table migration instead produces Laravel's
 * default `id` + `name` shape.
 *
 * That mismatch meant the schema could not be rebuilt from scratch, so the
 * test suite had nothing to run against. This migration reconciles the two.
 *
 * It is written as a new migration rather than an edit to the original,
 * because the original is already recorded in the production `migrations`
 * table and would never be re-run there. Every step is guarded, so against
 * production - where the columns already exist - this is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        // Fresh Laravel databases name the primary key `id`.
        if (!Schema::hasColumn('users', 'user_id') && Schema::hasColumn('users', 'id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('id', 'user_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'wallet_points')) {
                $table->integer('wallet_points')->default(0);
            }
            if (!Schema::hasColumn('users', 'role')) {
                // Plain string rather than an ENUM so roles can be added
                // without an ALTER that SQLite cannot perform.
                $table->string('role', 16)->default('student');
            }
            if (!Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(false);
            }
        });

        // Registration never populates `name` - student names live in the
        // student_information table - so a NOT NULL `name` column breaks
        // User::create() on a fresh database. Production has no such column.
        if (Schema::hasColumn('users', 'name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not reversed. Rolling this back on a database that was
        // already in the production shape would drop live columns
        // (wallet_points, role, is_active) that this migration did not create.
    }
};
