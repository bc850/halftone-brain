<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2E.1: per-consumer durable delivery state for integration outbox events.
 *
 * Outbox rows remain the immutable domain-event envelope. Delivery rows track
 * independent consumer progress so one consumer failure cannot erase another.
 * Never store credentials, raw tokens, or full provider payloads here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_outbox_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id')->nullable();
            $table->foreignId('organization_id')->nullable();
            $table->foreignId('integration_outbox_id');
            $table->string('consumer_key');
            $table->string('idempotency_key');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by_worker')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('provider_reference_json')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();

            $table->unique(['integration_outbox_id', 'consumer_key'], 'iod_outbox_consumer_uidx');
            $table->unique('idempotency_key', 'iod_idempotency_uidx');
            $table->index(['status', 'available_at'], 'iod_status_available_idx');
            $table->index(['organization_id', 'consumer_key', 'status'], 'iod_org_consumer_status_idx');
            $table->index(['parent_account_id', 'organization_id'], 'iod_pa_org_idx');
            $table->index(['locked_at', 'status'], 'iod_lease_idx');

            $table->foreign('parent_account_id', 'iod_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'iod_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'iod_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign('integration_outbox_id', 'iod_outbox_fk')
                ->references('id')
                ->on('integration_outbox')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox_deliveries');
    }
};
