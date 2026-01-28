<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add indexes on created_at columns for better performance during cleanup operations.
     */
    public function up(): void
    {
        // Add index on packages.created_at for faster cleanup queries
        Schema::table('packages', function (Blueprint $table) {
            $table->index('created_at', 'packages_created_at_index');
        });

        // Add index on flight_data.created_at for faster cleanup queries
        Schema::table('flight_data', function (Blueprint $table) {
            $table->index('created_at', 'flight_data_created_at_index');
        });

        // Add index on hotel_data.created_at for faster cleanup queries
        Schema::table('hotel_data', function (Blueprint $table) {
            $table->index('created_at', 'hotel_data_created_at_index');
        });

        // Add index on client_searches.created_at and package_created_at for faster cleanup queries
        Schema::table('client_searches', function (Blueprint $table) {
            $table->index('created_at', 'client_searches_created_at_index');
            $table->index('package_created_at', 'client_searches_package_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex('packages_created_at_index');
        });

        Schema::table('flight_data', function (Blueprint $table) {
            $table->dropIndex('flight_data_created_at_index');
        });

        Schema::table('hotel_data', function (Blueprint $table) {
            $table->dropIndex('hotel_data_created_at_index');
        });

        Schema::table('client_searches', function (Blueprint $table) {
            $table->dropIndex('client_searches_created_at_index');
            $table->dropIndex('client_searches_package_created_at_index');
        });
    }
};
