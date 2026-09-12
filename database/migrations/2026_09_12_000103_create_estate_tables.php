<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('immoware_object_number', 64)->nullable();
            $table->string('name', 200);
            $table->string('management_type', 16)->default('UNKNOWN');
            $table->string('street', 200)->nullable();
            $table->string('house_number', 20)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city', 120)->nullable();
            $table->externalIdentity();
            $table->index(['organization_id', 'immoware_object_number']);
            $table->index('management_type');
        });

        Schema::create('buildings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained('properties');
            $table->string('label', 200);
            $table->json('address_json')->nullable();
            $table->externalIdentity();
            $table->index('property_id');
        });

        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained('properties');
            $table->foreignId('building_id')->nullable()->constrained('buildings')->nullOnDelete();
            $table->string('unit_number', 64);
            $table->string('unit_type', 40)->nullable();
            $table->string('floor', 40)->nullable();
            $table->decimal('living_area_sqm', 10, 2)->nullable();
            $table->decimal('co_ownership_share_numerator', 14, 4)->nullable();
            $table->decimal('co_ownership_share_denominator', 14, 4)->nullable();
            $table->externalIdentity();
            $table->index(['property_id', 'unit_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('properties');
    }
};
