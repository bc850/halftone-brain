<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B.1: QuoteRevision adjustments (quote-level discounts and positive charges).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_revision_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->unsignedInteger('position');
            $table->string('adjustment_type');
            $table->string('description_snapshot');
            $table->string('method');
            $table->bigInteger('input_value')->default(0);
            $table->bigInteger('amount_cents')->default(0);
            $table->boolean('is_taxable')->default(false);
            $table->boolean('approval_required')->default(false);
            $table->json('approval_reason_json')->nullable();
            $table->timestamps();

            $table->unique(['quote_revision_id', 'position'], 'qradj_rev_pos_uidx');
            $table->unique(['quote_revision_id', 'id'], 'qradj_rev_id_uidx');
            $table->unique(['organization_id', 'id'], 'qradj_org_id_uidx');
            $table->index(['organization_id', 'quote_revision_id'], 'qradj_org_rev_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qradj_pa_org_idx');

            $table->foreign('parent_account_id', 'qradj_pa_fk')
                ->references('id')->on('parent_accounts')->restrictOnDelete();
            $table->foreign('organization_id', 'qradj_org_fk')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['parent_account_id', 'organization_id'], 'qradj_pa_org_fk')
                ->references(['parent_account_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['organization_id', 'quote_id'], 'qradj_org_quote_fk')
                ->references(['organization_id', 'id'])->on('quotes')->restrictOnDelete();
            $table->foreign(['quote_id', 'quote_revision_id'], 'qradj_quote_rev_fk')
                ->references(['quote_id', 'id'])->on('quote_revisions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_revision_adjustments');
    }
};
