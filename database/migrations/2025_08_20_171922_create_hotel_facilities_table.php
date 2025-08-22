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
        Schema::create('hotel_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->onDelete('cascade');
            $table->integer('facility_id');
            $table->string('facility_name');
            $table->string('facility_slug')->nullable();
            $table->string('icon')->nullable();
            $table->integer('group_id')->nullable();
            $table->string('group_name')->nullable();
            $table->enum('charge_mode', ['FREE', 'PAID', 'UNKNOWN'])->default('UNKNOWN');
            $table->boolean('is_offsite')->default(false);
            $table->enum('level', ['property', 'room'])->default('property');
            $table->json('extended_attributes')->nullable();
            $table->timestamps();
            
            $table->index(['hotel_id', 'facility_id']);
            $table->index('charge_mode');
            $table->index('level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_facilities');
    }
};
