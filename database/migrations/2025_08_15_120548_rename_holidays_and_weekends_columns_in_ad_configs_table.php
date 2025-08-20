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
        Schema::table('ad_configs', function (Blueprint $table) {
            $table->renameColumn('holidays_status', 'holiday_status');
            $table->renameColumn('holidays_last_run', 'holiday_last_run');
            $table->renameColumn('weekends_status', 'weekend_status');
            $table->renameColumn('weekends_last_run', 'weekend_last_run');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_configs', function (Blueprint $table) {
            $table->renameColumn('holidays_status', 'holiday_status');
            $table->renameColumn('holidays_last_run', 'holiday_last_run');
            $table->renameColumn('weekends_status', 'weekend_status');
            $table->renameColumn('weekends_last_run', 'weekend_last_run');
        });
    }
};
