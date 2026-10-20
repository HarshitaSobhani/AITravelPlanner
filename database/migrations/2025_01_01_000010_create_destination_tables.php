<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('url')->nullable();
            $table->string('license')->nullable();
            $table->text('attribution')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('destinations')->nullOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type', 16)->default('city'); // country | region | city
            $table->char('country_code', 2)->nullable()->index();
            $table->string('country_name')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('timezone', 64)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->text('summary')->nullable();
            $table->string('best_season')->nullable();
            $table->string('image_url')->nullable();
            $table->string('image_attribution')->nullable();
            $table->string('external_ref')->nullable()->index(); // e.g. "open-meteo-geocoding:2988507"
            $table->timestamps();

            $table->index(['type', 'name']);
        });

        Schema::create('points_of_interest', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('category', 32);
            $table->json('interests'); // list of interest keys this place serves
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('neighbourhood')->nullable();
            $table->string('website')->nullable();
            $table->unsignedSmallInteger('suggested_duration_minutes')->default(90);
            $table->unsignedTinyInteger('popularity')->default(50); // curation rank 0-100
            $table->json('opening_hours')->nullable(); // only when verified; null = unknown
            $table->string('accessibility_note')->nullable();
            $table->string('osm_ref')->nullable();
            $table->timestamp('coordinates_verified_at')->nullable();
            $table->string('image_url')->nullable();
            $table->string('image_attribution')->nullable();
            $table->timestamps();

            $table->unique(['destination_id', 'slug']);
            $table->index(['destination_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_of_interest');
        Schema::dropIfExists('destinations');
        Schema::dropIfExists('data_sources');
    }
};
