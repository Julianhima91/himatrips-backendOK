<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('ad_configs', function (Blueprint $table) {
            $table->enum('economic_status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->enum('weekends_status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->enum('holidays_status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->timestamp('economic_last_run')->nullable();
            $table->timestamp('weekends_last_run')->nullable();
            $table->timestamp('holidays_last_run')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_configs', function (Blueprint $table) {
            //
        });
    }
};
