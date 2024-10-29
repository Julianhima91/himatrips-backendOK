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
            $table->dropColumn('max_transit_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_configs', function (Blueprint $table) {
            $table->integer('max_transit_time')->default(0);
        });
    }
};
