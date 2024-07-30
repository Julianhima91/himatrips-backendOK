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
        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->string('codeIataAirport')->nullable();
            $table->decimal('latitudeAirport', 8, 2)->nullable();
            $table->decimal('longitudeAirport', 8, 2)->nullable();
            $table->string('nameAirport')->nullable();
            $table->string('nameCountry')->nullable();
            $table->string('phone')->nullable();
            $table->foreignId('origin_id')->nullable()->constrained()->onDelete('set null');
            $table->string('rapidapi_id')->nullable();
            $table->string('sky_id')->nullable();
            $table->string('entity_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
