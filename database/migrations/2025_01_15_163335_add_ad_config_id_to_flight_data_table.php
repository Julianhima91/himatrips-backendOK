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
        Schema::table('flight_data', function (Blueprint $table) {
            $table->unsignedBigInteger('ad_config_id')->nullable()->after('id');
            $table->foreign('ad_config_id')->references('id')->on('ad_configs')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_data', function (Blueprint $table) {
            $table->dropForeign(['ad_config_id']);
            $table->dropColumn('ad_config_id');
        });
    }
};
