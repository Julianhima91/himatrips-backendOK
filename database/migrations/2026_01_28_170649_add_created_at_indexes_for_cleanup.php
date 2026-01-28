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
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        // Add index on packages.created_at for faster cleanup queries
        if (!$this->indexExists($connection, $database, 'packages', 'packages_created_at_index')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->index('created_at', 'packages_created_at_index');
            });
        }

        // Add index on flight_data.created_at for faster cleanup queries
        if (!$this->indexExists($connection, $database, 'flight_data', 'flight_data_created_at_index')) {
            Schema::table('flight_data', function (Blueprint $table) {
                $table->index('created_at', 'flight_data_created_at_index');
            });
        }

        // Add index on hotel_data.created_at for faster cleanup queries
        if (!$this->indexExists($connection, $database, 'hotel_data', 'hotel_data_created_at_index')) {
            Schema::table('hotel_data', function (Blueprint $table) {
                $table->index('created_at', 'hotel_data_created_at_index');
            });
        }

        // Add index on client_searches.created_at for faster cleanup queries
        if (!$this->indexExists($connection, $database, 'client_searches', 'client_searches_created_at_index')) {
            Schema::table('client_searches', function (Blueprint $table) {
                $table->index('created_at', 'client_searches_created_at_index');
            });
        }

        // Add index on client_searches.package_created_at for faster cleanup queries
        if (!$this->indexExists($connection, $database, 'client_searches', 'client_searches_package_created_at_index')) {
            Schema::table('client_searches', function (Blueprint $table) {
                $table->index('package_created_at', 'client_searches_package_created_at_index');
            });
        }
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists($connection, $database, $table, $indexName): bool
    {
        $result = $connection->select(
            "SELECT COUNT(*) as count FROM information_schema.statistics 
             WHERE table_schema = ? 
             AND table_name = ? 
             AND index_name = ?",
            [$database, $table, $indexName]
        );

        return $result[0]->count > 0;
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
