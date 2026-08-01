<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2E.3A: append-only sanitized linkage between a delivery and a remote
 * provider resource. Never store request/response bodies, tokens, or secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_provider_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('integration_outbox_delivery_id');
            $table->string('provider', 32);
            $table->string('remote_resource_type', 64);
            $table->string('remote_id', 128);
            $table->string('remote_board_id', 64)->nullable();
            $table->string('remote_url', 191)->nullable();
            $table->string('provider_request_id', 128)->nullable();
            $table->boolean('idempotency_replayed')->default(false);
            $table->string('api_version', 32);
            $table->timestamp('linked_at');
            $table->string('discovery_method', 32);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['integration_outbox_delivery_id', 'provider', 'remote_resource_type'],
                'ipr_delivery_provider_type_uidx',
            );
            $table->unique(
                ['organization_id', 'provider', 'remote_resource_type', 'remote_id'],
                'ipr_org_provider_remote_uidx',
            );
            $table->index(['parent_account_id', 'organization_id'], 'ipr_pa_org_idx');
            $table->index(['provider', 'remote_resource_type'], 'ipr_provider_type_idx');

            $table->foreign('parent_account_id', 'ipr_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'ipr_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'ipr_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign('integration_outbox_delivery_id', 'ipr_delivery_fk')
                ->references('id')
                ->on('integration_outbox_deliveries')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_provider_receipts');
    }
};
