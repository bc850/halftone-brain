<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2D.1: append-only delivery status transition log.
 *
 * Created_at only. Safe metadata only — never raw tokens or provider secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_delivery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->foreignId('quote_delivery_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->json('metadata_json')->nullable();
            $table->unsignedBigInteger('actor_membership_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('occurred_at');
            $table->uuid('correlation_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['quote_delivery_id', 'occurred_at'], 'qde_delivery_occurred_idx');
            $table->index(['quote_id', 'occurred_at'], 'qde_quote_occurred_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qde_pa_org_idx');

            $table->foreign('parent_account_id', 'qde_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qde_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qde_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_id'], 'qde_org_quote_fk')
                ->references(['organization_id', 'id'])
                ->on('quotes')
                ->restrictOnDelete();

            $table->foreign(['quote_id', 'quote_revision_id'], 'qde_quote_rev_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_delivery_id'], 'qde_org_delivery_fk')
                ->references(['organization_id', 'id'])
                ->on('quote_deliveries')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'actor_membership_id'], 'qde_org_actor_mem_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign('actor_user_id', 'qde_actor_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_delivery_events');
    }
};
