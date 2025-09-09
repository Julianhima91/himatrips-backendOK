<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_passenger_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('adults');
            $table->unsignedInteger('children');
            $table->unsignedBigInteger('total_flights')->default(0);
            $table->timestamps();

            $table->unique(['adults', 'children'], 'unique_passenger_combo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_passenger_stats');
    }
};
