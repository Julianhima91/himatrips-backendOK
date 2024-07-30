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
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('outbound_flight_id');
            $table->unsignedInteger('inbound_flight_id');
            $table->unsignedInteger('hotel_data_id');
            $table->decimal('commission', 8, 2);
            $table->decimal('total_price', 8, 2);
            $table->string('batch_id')->nullable();
            $table->foreignId('package_config_id')->nullable();
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
        Schema::dropIfExists('packages');
    }
};
