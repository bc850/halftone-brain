<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2E.3A: per-organization external provider configuration.
 *
 * Credentials and tokens must never be stored here. Authentication for Monday
 * uses environment/secret configuration only (future checkpoint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_integration_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->string('provider', 32);
            $table->boolean('enabled')->default(false);
            $table->string('api_version', 32)->default('2026-07');
            $table->string('board_id', 64)->nullable();
            $table->string('group_id', 64)->nullable();
            $table->string('item_name_template', 191)->default('{quote_number} — {company_name}');
            $table->string('line_detail_mode', 32)->default('summary');
            $table->json('column_mapping_json')->nullable();
            $table->json('status_label_mappings_json')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->string('last_validation_status', 32)->nullable();
            $table->string('last_validation_error_code', 80)->nullable();
            $table->text('last_validation_error_message')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'provider'], 'ois_org_provider_uidx');
            $table->index(['parent_account_id', 'organization_id'], 'ois_pa_org_idx');
            $table->index(['organization_id', 'enabled'], 'ois_org_enabled_idx');
            $table->index(['provider', 'enabled'], 'ois_provider_enabled_idx');

            $table->foreign('parent_account_id', 'ois_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'ois_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'ois_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_integration_settings');
    }
};
