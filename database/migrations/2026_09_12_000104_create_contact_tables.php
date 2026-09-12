<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
            $table->string('register_number', 64)->nullable();
            $table->json('addresses')->nullable();
            $table->externalIdentity();
            $table->index(['organization_id', 'name']);
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16)->default('person');
            $table->string('salutation', 40)->nullable();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->date('birth_date')->nullable();
            $table->json('emails')->nullable();
            $table->json('phones')->nullable();
            $table->json('addresses')->nullable();
            $table->string('vcard_uid', 255)->nullable();
            $table->string('vcard_href', 1024)->nullable();
            $table->string('vcard_rev', 40)->nullable();
            $table->char('similarity_hash', 64)->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestamp('personal_data_erased_at')->nullable();
            $table->externalIdentity();
            $table->index(['organization_id', 'last_name', 'first_name']);
            $table->index('vcard_uid');
            $table->index('similarity_hash');
            $table->index('merged_into_id');
        });

        Schema::create('contact_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('value_normalized', 254);
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();
            $table->unique(['contact_id', 'kind', 'value_normalized']);
            $table->index(['kind', 'value_normalized']);
        });

        Schema::create('contact_merges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_contact_id')->constrained('contacts');
            $table->foreignId('target_contact_id')->constrained('contacts');
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at');
            $table->text('reason')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index(['source_contact_id', 'undone_at']);
            $table->index('target_contact_id');
        });

        Schema::create('contact_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts');
            $table->string('role', 32);
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->externalIdentity();
            $table->index(['contact_id', 'role']);
            $table->index(['property_id', 'role']);
            $table->index(['unit_id', 'role', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_roles');
        Schema::dropIfExists('contact_merges');
        Schema::dropIfExists('contact_identifiers');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('companies');
    }
};
