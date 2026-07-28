<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C.1: exemption certificates held by an organization for one of its companies.
 *
 * An exemption category alone never grants exemption. A certificate only supports
 * an exempt outcome when it is verified, within its effective window, and issued
 * for the jurisdiction being taxed. Nonprofit status, school status, and similar
 * categories are claims that still require a verified certificate.
 *
 * `certificate_number` is sensitive and must never appear in customer-facing
 * payloads or in tax calculation snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_company_tax_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('organization_company_id');
            $table->string('exemption_category');
            $table->string('jurisdiction_state');
            $table->string('certificate_form_type');
            $table->string('certificate_number')->nullable();
            $table->string('evidence_reference')->nullable();
            $table->date('effective_date');
            $table->date('expiration_date')->nullable();
            $table->string('verification_status')->default('pending');
            $table->unsignedBigInteger('verified_by_membership_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'octc_org_id_uidx');
            $table->index(['organization_id', 'organization_company_id'], 'octc_org_company_idx');
            $table->index(['organization_company_id', 'jurisdiction_state'], 'octc_company_state_idx');
            $table->index(['organization_id', 'verification_status'], 'octc_org_status_idx');
            $table->index(['parent_account_id', 'organization_id'], 'octc_pa_org_idx');

            $table->foreign('parent_account_id', 'octc_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'octc_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'octc_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'organization_company_id'], 'octc_org_company_fk')
                ->references(['organization_id', 'id'])
                ->on('organization_companies')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'verified_by_membership_id'], 'octc_org_verifier_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_company_tax_certificates');
    }
};
