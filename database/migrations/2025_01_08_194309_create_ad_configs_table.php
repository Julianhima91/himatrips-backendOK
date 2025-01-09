<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ad_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('origin_id')->constrained()->cascadeOnDelete();
            $table->integer('refresh_hours')->default(24);
            $table->json('extra_options');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_configs');
    }
};
