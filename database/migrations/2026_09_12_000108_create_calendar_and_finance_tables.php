<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->string('ical_uid', 255)->nullable();
            $table->string('ical_href', 1024)->nullable();
            $table->string('calendar_path_hash', 64)->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('location', 500)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('timezone', 64)->nullable();
            $table->string('status', 32)->nullable();
            $table->string('recurrence_rule', 500)->nullable();
            $table->string('sequence', 20)->nullable();
            $table->json('attendees')->nullable();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->externalIdentity();
            $table->index('ical_uid');
            $table->index(['starts_at', 'ends_at']);
            $table->index('status');
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('creditor_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('invoice_number', 64)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->bigInteger('gross_cents')->nullable();
            $table->bigInteger('net_cents')->nullable();
            $table->bigInteger('vat_cents')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status', 32)->nullable();
            $table->externalIdentity();
            $table->index(['property_id', 'invoice_date']);
            $table->index('invoice_number');
            $table->index('status');
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('kind', 8);
            $table->date('booking_date')->nullable();
            $table->date('value_date')->nullable();
            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('debit_account', 20)->nullable();
            $table->string('credit_account', 20)->nullable();
            $table->string('cost_center', 40)->nullable();
            $table->string('document_field', 64)->nullable();
            $table->string('text', 500)->nullable();
            $table->string('end_to_end_id', 35)->nullable();
            $table->string('acct_svcr_ref', 64)->nullable();
            $table->char('row_hash', 64);
            $table->unsignedSmallInteger('occurrence_no')->default(1);
            $table->unsignedBigInteger('import_payload_id')->nullable();
            $table->externalIdentity();
            $table->index(['property_id', 'booking_date']);
            $table->index('row_hash');
            $table->index('end_to_end_id');
            $table->index('import_payload_id');
        });

        Schema::create('open_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('kind', 24);
            $table->date('due_date')->nullable();
            $table->bigInteger('amount_cents');
            $table->bigInteger('open_cents');
            $table->char('currency', 3)->default('EUR');
            $table->date('as_of_date');
            $table->externalIdentity(uniqueExternal: false);
            $table->unique(['organization_id', 'source_system', 'external_id_hash', 'as_of_date'], 'open_items_external_asof_unique');
            $table->index(['property_id', 'as_of_date']);
            $table->index(['contact_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('calendar_events');
    }
};
