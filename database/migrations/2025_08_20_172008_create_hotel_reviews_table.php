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
        Schema::create('hotel_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->onDelete('cascade');
            $table->bigInteger('booking_review_id')->unique();
            $table->bigInteger('user_id')->nullable();
            $table->string('reviewer_name');
            $table->string('reviewer_country')->nullable();
            $table->decimal('average_score', 3, 1);
            $table->text('positive_text')->nullable();
            $table->text('negative_text')->nullable();
            $table->string('title')->nullable();
            $table->enum('customer_type', [
                'YOUNG_COUPLE',
                'FAMILY_WITH_YOUNG_CHILDREN',
                'FAMILY_WITH_OLDER_CHILDREN',
                'SOLO_TRAVELLER',
                'BUSINESS',
                'GROUP',
                'MATURE_COUPLE',
                'OTHER'
            ])->nullable();
            $table->enum('purpose_type', ['LEISURE', 'BUSINESS', 'OTHER'])->nullable();
            $table->datetime('review_date');
            $table->string('language', 10)->default('en');
            $table->string('room_type')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->string('avatar_url')->nullable();
            $table->timestamps();
            
            $table->index('hotel_id');
            $table->index('average_score');
            $table->index('review_date');
            $table->index('customer_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_reviews');
    }
};
