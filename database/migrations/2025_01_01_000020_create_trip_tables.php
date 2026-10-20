<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('travelers');
            $table->char('currency', 3);
            $table->unsignedBigInteger('budget_minor')->nullable();
            $table->string('pace', 16);
            $table->text('notes')->nullable(); // private; never shared
            $table->text('summary')->nullable(); // optional AI-written overview
            $table->json('plan_warnings')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'start_date']);
        });

        Schema::create('trip_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('interests');
            $table->text('accessibility')->nullable();
            $table->text('dietary')->nullable();
            $table->string('accommodation')->nullable();
            $table->decimal('accommodation_latitude', 10, 7)->nullable();
            $table->decimal('accommodation_longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('itinerary_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('position');
            $table->string('title')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['trip_id', 'position']);
        });

        Schema::create('itinerary_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('itinerary_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('point_of_interest_id')->nullable()->constrained('points_of_interest')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name'); // snapshot of POI name or custom stop name
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->time('start_time')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->text('notes')->nullable();
            $table->string('source', 12)->default('generated'); // generated | manual
            $table->boolean('is_locked')->default(false); // user touched it: regeneration keeps it
            $table->boolean('time_locked')->default(false); // user set the start time explicitly
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->index(['itinerary_day_id', 'position']);
            $table->index(['trip_id', 'point_of_interest_id']);
        });

        Schema::create('saved_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'destination_id']);
        });

        Schema::create('saved_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('point_of_interest_id')->constrained('points_of_interest')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'point_of_interest_id']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->char('currency', 3);
            $table->unsignedBigInteger('estimated_minor')->default(0);
            $table->unsignedBigInteger('actual_minor')->nullable();
            // User-entered rate: 1 unit of `currency` = rate units of the trip currency. Null when same currency.
            $table->decimal('manual_rate', 18, 8)->nullable();
            $table->string('rate_note')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'expense_category_id']);
        });

        Schema::create('trip_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->boolean('include_budget_totals')->default(false);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_shares');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('saved_places');
        Schema::dropIfExists('saved_destinations');
        Schema::dropIfExists('itinerary_stops');
        Schema::dropIfExists('itinerary_days');
        Schema::dropIfExists('trip_preferences');
        Schema::dropIfExists('trips');
    }
};
