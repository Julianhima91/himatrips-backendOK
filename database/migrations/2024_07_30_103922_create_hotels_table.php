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
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->integer('hotel_id')->nullable();
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('fax')->nullable();
            $table->integer('stars')->nullable();
            $table->integer('stars_id')->nullable();
            $table->string('longitude')->nullable();
            $table->string('latitude')->nullable();
            $table->boolean('is_apartment')->nullable();
            $table->string('giata_code')->nullable();
            $table->integer('city_id')->nullable();
            $table->string('city')->nullable();
            $table->string('iso_code')->nullable();
            $table->string('country')->nullable();
            $table->integer('country_id')->nullable();
            $table->decimal('review_score')->nullable();
            $table->integer('review_count')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('hotel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotels');
    }
};
