<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'password_set_at')) {
            Schema::table('users', function (Blueprint $table) {
                // Google accounts start with an unusable generated hash. This
                // timestamp tells us when the student chose a real password.
                $table->timestamp('password_set_at')->nullable()->after('password');
            });
        }

        if (!Schema::hasTable('user_auth_identities')) {
            Schema::create('user_auth_identities', function (Blueprint $table) {
                $table->bigIncrements('auth_identity_id');
                $table->unsignedBigInteger('user_id');
                $table->string('provider', 32);
                // Google's immutable `sub`, not the mutable email address.
                $table->string('provider_subject', 255);
                $table->string('provider_email')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'provider_subject']);
                $table->unique(['user_id', 'provider']);
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_auth_identities');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'password_set_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('password_set_at'));
        }
    }
};
