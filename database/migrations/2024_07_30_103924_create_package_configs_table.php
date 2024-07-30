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
        Schema::create('package_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_origin_id')->nullable()->references('id')->on('destination_origins')->onDelete('cascade');
            $table->json('destination_airports')->nullable();
            $table->json('origin_airports')->nullable();
            $table->json('airlines')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->json('number_of_nights')->nullable();
            $table->integer('max_stop_count')->nullable();
            $table->integer('max_transit_time')->default(0);
            $table->enum('room_basis', ['BB', 'HB', 'FB', 'AI', 'CB', 'RO', 'BD'])->nullable();
            $table->enum('commission_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('commission_amount', 8, 2)->nullable();
            $table->decimal('price_limit', 8, 2)->nullable();
            $table->unsignedInteger('update_frequency')->default(0);
            $table->timestamp('last_processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_configs');
    }
};
