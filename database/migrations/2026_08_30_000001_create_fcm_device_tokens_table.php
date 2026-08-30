<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('token')->unique();
            $table->string('device_id')->nullable();
            $table->string('platform', 20)->default('android');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            // The live users.user_id column predates Laravel migrations and may
            // use a different integer width/signedness. Keep this table
            // compatible with the existing production schema; the API still
            // validates that user_id belongs to the authenticated user.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_device_tokens');
    }
};
