<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2D.1: durable post-transaction integration outbox.
 *
 * Status transitions belong to a future dispatcher — not unrestricted mass
 * assignment. Payloads may carry safe IDs and references only; never raw tokens,
 * credentials, certificate secrets, or internal cost details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id')->nullable();
            $table->foreignId('organization_id')->nullable();
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type');
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('payload_json');
            $table->string('idempotency_key');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by_worker')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();

            $table->unique('idempotency_key', 'iob_idempotency_uidx');
            $table->index(['status', 'available_at'], 'iob_status_available_idx');
            $table->index(['aggregate_type', 'aggregate_id'], 'iob_aggregate_idx');
            $table->index(['organization_id', 'event_type'], 'iob_org_event_idx');
            $table->index(['parent_account_id', 'organization_id'], 'iob_pa_org_idx');

            $table->foreign('parent_account_id', 'iob_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'iob_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'iob_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox');
    }
};
