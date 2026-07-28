<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2D.1: customer access tokens for viewing and responding to a quote.
 *
 * Only the SHA-256 hex digest is stored. Raw tokens are returned once from the
 * generator and never persisted, logged, audited, or placed in outbox payloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_customer_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->foreignId('quote_revision_document_id');
            $table->char('token_hash', 64);
            $table->string('purpose')->default('view_and_respond');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->unsignedBigInteger('created_by_membership_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('terminal_response_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'qcat_token_hash_uidx');
            $table->unique(['organization_id', 'id'], 'qcat_org_id_uidx');
            $table->index(['quote_revision_id', 'expires_at'], 'qcat_rev_expires_idx');
            $table->index(['organization_id', 'revoked_at'], 'qcat_org_revoked_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qcat_pa_org_idx');

            $table->foreign('parent_account_id', 'qcat_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qcat_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qcat_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_id'], 'qcat_org_quote_fk')
                ->references(['organization_id', 'id'])
                ->on('quotes')
                ->restrictOnDelete();

            $table->foreign(['quote_id', 'quote_revision_id'], 'qcat_quote_rev_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();

            $table->foreign(['quote_revision_id', 'quote_revision_document_id'], 'qcat_rev_document_fk')
                ->references(['quote_revision_id', 'id'])
                ->on('quote_revision_documents')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'created_by_membership_id'], 'qcat_org_creator_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign('created_by_user_id', 'qcat_creator_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_customer_access_tokens');
    }
};
