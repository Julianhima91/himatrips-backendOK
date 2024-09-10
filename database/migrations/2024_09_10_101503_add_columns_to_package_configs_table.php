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
        Schema::table('package_configs', function (Blueprint $table) {
            $table->boolean('is_direct_flight')->default(false);
            $table->decimal('commission_percentage', 5, 2)->default(5);
            $table->boolean('prioritize_morning_flights')->default(false);
            $table->boolean('prioritize_evening_flights')->default(false);
            $table->integer('max_wait_time')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_configs', function (Blueprint $table) {
            $table->dropColumn([
                'is_direct_flight',
                'commission_percentage',
                'prioritize_morning_flights',
                'prioritize_evening_flights',
                'max_wait_time',
            ]);
        });
    }
};
