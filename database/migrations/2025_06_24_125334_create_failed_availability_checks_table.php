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
        Schema::create('failed_availability_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('origin_airport_id')->nullable();
            $table->unsignedBigInteger('destination_airport_id')->nullable();
            $table->string('year_month');
            $table->boolean('is_return_flight');
            $table->unsignedBigInteger('destination_origin_id');
            $table->text('error_message');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_availability_checks');
    }
};
