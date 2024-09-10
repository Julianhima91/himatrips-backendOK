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
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->string('city');
            $table->string('country');
            $table->boolean('show_in_homepage')->default(false);
            //            $table->boolean('is_direct_flight')->default(false);
            //            $table->decimal('commission_percentage', 5, 2)->default(0);
            //            $table->boolean('prioritize_morning_flights')->default(false);
            //            $table->boolean('prioritize_evening_flights')->default(false);
            //            $table->integer('max_stop_count')->default(0);
            //            $table->integer('max_wait_time')->default(0);
            $table->time('morning_flight_start_time')->nullable();
            $table->time('morning_flight_end_time')->nullable();
            $table->time('evening_flight_start_time')->nullable();
            $table->time('evening_flight_end_time')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('destinations');
    }
};
