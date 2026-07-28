<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2D.1: append-only terminal customer response events.
 *
 * Exactly one response per quote revision. IP addresses are stored encrypted;
 * user-agent snapshots are capped at 512 characters. Acceptance requires terms
 * acceptance and a typed name at the domain layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_customer_response_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->foreignId('quote_revision_document_id');
            $table->unsignedBigInteger('quote_customer_access_token_id')->nullable();
            $table->string('response');
            $table->string('source');
            $table->string('typed_name_snapshot');
            $table->string('customer_email_snapshot')->nullable();
            $table->boolean('terms_accepted')->default(false);
            $table->char('terms_document_checksum', 64);
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('employee_membership_id')->nullable();
            $table->unsignedBigInteger('employee_user_id')->nullable();
            $table->text('employee_recorded_reason')->nullable();
            $table->text('ip_address_encrypted')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('occurred_at');
            $table->uuid('correlation_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('quote_revision_id', 'qcre_revision_uidx');
            $table->unique(['organization_id', 'id'], 'qcre_org_id_uidx');
            $table->index(['quote_id', 'occurred_at'], 'qcre_quote_occurred_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qcre_pa_org_idx');

            $table->foreign('parent_account_id', 'qcre_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qcre_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qcre_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_id'], 'qcre_org_quote_fk')
                ->references(['organization_id', 'id'])
                ->on('quotes')
                ->restrictOnDelete();

            $table->foreign(['quote_id', 'quote_revision_id'], 'qcre_quote_rev_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();

            $table->foreign(['quote_revision_id', 'quote_revision_document_id'], 'qcre_rev_document_fk')
                ->references(['quote_revision_id', 'id'])
                ->on('quote_revision_documents')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_customer_access_token_id'], 'qcre_org_token_fk')
                ->references(['organization_id', 'id'])
                ->on('quote_customer_access_tokens')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'employee_membership_id'], 'qcre_org_employee_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign('employee_user_id', 'qcre_employee_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_customer_response_events');
    }
};
