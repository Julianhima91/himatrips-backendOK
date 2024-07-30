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
        Schema::create('flight_itineraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_data_id')->nullable();
            $table->dateTime('departure')->nullable();
            $table->dateTime('arrival')->nullable();
            $table->decimal('price')->nullable();
            $table->string('airline')->nullable();
            $table->integer('stop_count')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_itineraries');
    }
};
