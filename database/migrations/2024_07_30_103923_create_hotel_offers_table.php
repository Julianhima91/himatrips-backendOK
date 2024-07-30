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
        Schema::create('hotel_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_data_id')->references('id')->on('hotel_data')->cascadeOnDelete();
            $table->string('room_basis');
            $table->text('room_type');
            $table->decimal('price');
            $table->decimal('total_price_for_this_offer', 10, 2);
            $table->date('reservation_deadline')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index('hotel_data_id');
            $table->index('room_basis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_offers');
    }
};
