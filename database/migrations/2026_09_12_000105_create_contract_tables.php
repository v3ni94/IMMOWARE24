<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
            $table->string('contract_number', 64)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->bigInteger('net_rent_cents')->nullable();
            $table->bigInteger('ancillary_cents')->nullable();
            $table->bigInteger('heating_cents')->nullable();
            $table->bigInteger('total_cents')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 32)->nullable();
            $table->externalIdentity();
            $table->index(['unit_id', 'start_date']);
            $table->index('contract_number');
            $table->index('status');
        });

        Schema::create('contract_parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts');
            $table->string('party_role', 16);
            $table->timestamps();
            $table->unique(['contract_id', 'contact_id', 'party_role']);
            $table->index('contact_id');
        });

        Schema::create('ownerships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
            $table->foreignId('contact_id')->constrained('contacts');
            $table->decimal('share_numerator', 14, 4)->nullable();
            $table->decimal('share_denominator', 14, 4)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->bigInteger('house_money_cents')->nullable();
            $table->externalIdentity();
            $table->index(['unit_id', 'valid_from']);
            $table->index('contact_id');
        });

        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->text('iban');
            $table->char('iban_hash', 64);
            $table->string('iban_masked', 40);
            $table->string('bic', 11)->nullable();
            $table->string('account_holder', 200)->nullable();
            $table->string('mandate_reference', 35)->nullable();
            $table->externalIdentity();
            $table->unique(['owner_type', 'owner_id', 'iban_hash']);
            $table->index('iban_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('ownerships');
        Schema::dropIfExists('contract_parties');
        Schema::dropIfExists('contracts');
    }
};
