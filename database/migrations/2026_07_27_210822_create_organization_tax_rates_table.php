<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C.1: organization-owned jurisdiction + rate rows.
 *
 * Jurisdiction and rate live in one table for v1 because an organization
 * configures a rate for a jurisdiction it names itself; no shared rate registry
 * exists yet. Rates are parts-per-million (denominator 1,000,000) so 8% is
 * 80,000 ppm and no floating point value is ever stored.
 *
 * Effective-period overlap is rejected by OrganizationTaxRateService::assertNoOverlap()
 * rather than a database exclusion constraint, which MySQL does not support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->string('country', 2);
            $table->string('state')->nullable();
            $table->string('county')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->json('routing_metadata_json')->nullable();
            $table->string('jurisdiction_code');
            $table->string('display_name');
            $table->unsignedBigInteger('rate_ppm');
            $table->date('effective_from');
            $table->date('effective_through')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source_note')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'otr_org_id_uidx');
            $table->index(['organization_id', 'jurisdiction_code', 'effective_from'], 'otr_org_juris_from_idx');
            $table->index(['organization_id', 'country', 'state'], 'otr_org_country_state_idx');
            $table->index(['organization_id', 'is_active'], 'otr_org_active_idx');
            $table->index(['parent_account_id', 'organization_id'], 'otr_pa_org_idx');

            $table->foreign('parent_account_id', 'otr_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'otr_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'otr_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_tax_rates');
    }
};
