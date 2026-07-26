<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A: append-only quote status transition log.
 * No updated_at. Mirrors AuditEvent / source price event guards in the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_user_id')->nullable();
            $table->foreignId('actor_membership_id')->nullable();
            $table->string('transition_source');
            $table->json('metadata_json')->nullable();
            $table->timestamp('occurred_at');
            $table->uuid('correlation_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['quote_id', 'occurred_at'], 'qse_quote_occurred_idx');
            $table->index(['quote_revision_id', 'occurred_at'], 'qse_revision_occurred_idx');
            $table->index(['organization_id', 'quote_id'], 'qse_org_quote_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qse_pa_org_idx');

            $table->foreign('parent_account_id', 'qse_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qse_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qse_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_id'], 'qse_org_quote_fk')
                ->references(['organization_id', 'id'])
                ->on('quotes')
                ->restrictOnDelete();

            $table->foreign(['quote_id', 'quote_revision_id'], 'qse_quote_revision_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();

            $table->foreign('actor_user_id', 'qse_actor_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'actor_membership_id'], 'qse_org_actor_mem_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_status_events');
    }
};
