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
            $table->integer('min_nights_stay');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_configs', function (Blueprint $table) {
            $table->dropColumn('min_nights_stay');
        });
    }
};
