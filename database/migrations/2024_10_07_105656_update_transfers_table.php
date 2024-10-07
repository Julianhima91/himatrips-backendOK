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
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('price');

            $table->integer('adult_price')->after('id');
            $table->integer('children_price')->default(0)->after('adult_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        schema::table('transfers', function (Blueprint $table) {
            $table->integer('price')->after('id');

            $table->dropColumn(['adult_price', 'children_price']);
        });
    }
};
