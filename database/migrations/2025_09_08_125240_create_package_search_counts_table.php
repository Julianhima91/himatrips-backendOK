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
        Schema::create('package_search_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_config_id')->constrained()->cascadeOnDelete();
            $table->integer('batch_count')->default(0);
            $table->timestamps();

            $table->unique('package_config_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_search_counts');
    }
};
