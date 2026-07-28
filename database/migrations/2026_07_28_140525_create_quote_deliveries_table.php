<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2D.1: delivery-attempt identities for customer quote documents.
 *
 * Status transitions are owned by future delivery services — not unrestricted
 * mass assignment. A revision becomes customer-visible "sent" only after
 * provider acceptance or authorized manual recording; pending is not sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->foreignId('quote_revision_document_id');
            $table->string('channel');
            $table->string('status')->default('pending');
            $table->string('recipient_name_snapshot');
            $table->string('recipient_email_snapshot');
            $table->json('cc_recipients_snapshot_json')->nullable();
            $table->string('provider_key')->nullable();
            $table->string('external_message_id')->nullable();
            $table->string('idempotency_key');
            $table->unsignedBigInteger('requested_by_membership_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'qd_idempotency_uidx');
            $table->unique(['organization_id', 'id'], 'qd_org_id_uidx');
            $table->index(['quote_revision_id', 'created_at'], 'qd_rev_created_idx');
            $table->index(['organization_id', 'status'], 'qd_org_status_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qd_pa_org_idx');

            $table->foreign('parent_account_id', 'qd_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qd_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qd_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_id'], 'qd_org_quote_fk')
                ->references(['organization_id', 'id'])
                ->on('quotes')
                ->restrictOnDelete();

            $table->foreign(['quote_id', 'quote_revision_id'], 'qd_quote_rev_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();

            $table->foreign(['quote_revision_id', 'quote_revision_document_id'], 'qd_rev_document_fk')
                ->references(['quote_revision_id', 'id'])
                ->on('quote_revision_documents')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'requested_by_membership_id'], 'qd_org_requester_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign('requested_by_user_id', 'qd_requester_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_deliveries');
    }
};
