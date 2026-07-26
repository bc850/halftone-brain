<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A: QuoteRevision — immutable customer/financial version after send.
 * No line items in 2A. Money columns are integer cents; currency USD default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->unsignedInteger('revision_number');
            $table->unsignedBigInteger('source_revision_id')->nullable();
            $table->string('status');
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('currency_code', 3)->default('USD');
            $table->date('issue_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->text('introduction')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('terms_text')->nullable();
            $table->text('internal_notes')->nullable();
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('taxable_amount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('grand_total_cents')->default(0);
            $table->bigInteger('requested_deposit_cents')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->json('approval_reason_snapshot')->nullable();
            $table->timestamp('pricing_snapshotted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['quote_id', 'revision_number'], 'qrev_quote_number_uidx');
            $table->unique(['quote_id', 'id'], 'qrev_quote_id_uidx');
            $table->unique(['organization_id', 'id'], 'qrev_org_id_uidx');
            $table->index(['organization_id', 'quote_id', 'status'], 'qrev_org_quote_status_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qrev_pa_org_idx');

            $table->foreign('parent_account_id', 'qrev_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qrev_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qrev_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_id'], 'qrev_org_quote_fk')
                ->references(['organization_id', 'id'])
                ->on('quotes')
                ->restrictOnDelete();

            $table->foreign(['quote_id', 'source_revision_id'], 'qrev_source_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_revisions');
    }
};
