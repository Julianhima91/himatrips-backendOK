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
        Schema::table('origins', function (Blueprint $table) {
            $table->unsignedInteger('search_count')->default(0);
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->unsignedInteger('search_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('origins', function (Blueprint $table) {
            $table->dropColumn('search_count');
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn('search_count');
        });
    }
};
