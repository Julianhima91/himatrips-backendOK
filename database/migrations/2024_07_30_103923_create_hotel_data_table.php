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
        Schema::create('hotel_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->date('check_in_date');
            $table->integer('number_of_nights');
            $table->integer('room_count')->default(1);
            $table->integer('adults');
            $table->integer('children');
            $table->integer('infants')->nullable();
            $table->unsignedBigInteger('package_config_id');
            $table->timestamps();

            $table->index('hotel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_data');
    }
};
