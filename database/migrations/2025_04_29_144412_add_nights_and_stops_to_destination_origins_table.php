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
        Schema::table('destination_origins', function (Blueprint $table) {
            $table->integer('min_nights')->nullable();
            $table->integer('max_nights')->nullable();
            $table->integer('stops')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('destination_origins', function (Blueprint $table) {
            $table->dropColumn(['min_nights', 'max_nights', 'stops']);
        });
    }
};
