<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C.1: per-organization tax configuration.
 *
 * Holds only how this organization decides tax internally. It stores no
 * connector credentials and no external provider configuration, because
 * v1 calculates from organization-owned rate rows exclusively.
 *
 * `default_country` / `default_state` are defaults for jurisdiction resolution,
 * not a legal determination — a billing address never by itself determines the
 * taxing jurisdiction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->string('default_country', 2)->default('US');
            $table->string('default_state')->nullable();
            $table->string('sourcing_strategy')->default('delivery');
            $table->string('registration_reference')->nullable();
            $table->json('registration_metadata_json')->nullable();
            $table->boolean('tax_calculation_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('configuration_version')->default(1);
            $table->timestamps();

            $table->unique('organization_id', 'otp_org_uidx');
            $table->unique(['organization_id', 'id'], 'otp_org_id_uidx');
            $table->index(['parent_account_id', 'organization_id'], 'otp_pa_org_idx');

            $table->foreign('parent_account_id', 'otp_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'otp_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'otp_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_tax_profiles');
    }
};
