<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_searches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('package_config_id');
            $table->string('batch_id')->index();
            $table->string('origin_name');
            $table->foreignIdFor(\App\Models\Origin::class, 'origin_id');
            $table->string('destination_name');
            $table->foreignIdFor(\App\Models\Destination::class, 'destination_id');
            $table->foreignIdFor(\App\Models\Package::class, 'package_id');
            $table->unsignedInteger('adults')->default(0);
            $table->unsignedInteger('children')->default(0);
            $table->unsignedInteger('infants')->default(0);
            $table->unsignedInteger('number_of_nights')->default(0);
            $table->date('checkin_date')->nullable();
            $table->foreignIdFor(\App\Models\FlightData::class, 'inbound_flight_id');
            $table->json('rooms')->nullable();
            $table->boolean('direct_flights_only')->default(false);
            $table->text('url')->nullable()->comment('Search link');
            $table->timestamps();
            $table->timestamp('package_created_at')->nullable();
            $table->softDeletes();

            $table->foreign('package_config_id')
                ->references('id')
                ->on('package_configs')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_searches');
    }
};
