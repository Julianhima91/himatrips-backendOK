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
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outbound_flight_id');
            $table->unsignedBigInteger('inbound_flight_id');
            $table->foreignId('hotel_data_id');
            $table->decimal('commission', 8, 2);
            $table->decimal('total_price', 10, 2);
            $table->string('batch_id');
            $table->foreignId('ad_config_id');
            $table->timestamps();

            $table->index('hotel_data_id');
            $table->index('batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
