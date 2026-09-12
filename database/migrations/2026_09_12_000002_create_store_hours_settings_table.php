<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_hours_settings', function (Blueprint $table) {
            $table->id();
            $table->string('open_time', 5);
            $table->string('close_time', 5);
            $table->string('open_days', 20);
            $table->unsignedSmallInteger('slot_minutes');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_hours_settings');
    }
};
