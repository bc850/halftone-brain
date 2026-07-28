<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C.1: append-only approval decision log.
 *
 * Created_at only. `reason` is nullable at the column level because approvals do
 * not require one; rejections do, and that is enforced in the domain layer so the
 * requirement can carry a useful message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_approval_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_approval_request_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->string('decision');
            $table->unsignedBigInteger('approver_membership_id');
            $table->unsignedBigInteger('approver_user_id');
            $table->text('reason')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('decided_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organization_id', 'id'], 'qapd_org_id_uidx');
            $table->index(['quote_approval_request_id', 'decided_at'], 'qapd_request_decided_idx');
            $table->index(['quote_revision_id', 'decided_at'], 'qapd_rev_decided_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qapd_pa_org_idx');

            $table->foreign('parent_account_id', 'qapd_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qapd_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qapd_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_approval_request_id'], 'qapd_org_request_fk')
                ->references(['organization_id', 'id'])
                ->on('quote_approval_requests')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_id'], 'qapd_org_quote_fk')
                ->references(['organization_id', 'id'])
                ->on('quotes')
                ->restrictOnDelete();

            $table->foreign(['quote_id', 'quote_revision_id'], 'qapd_quote_rev_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'approver_membership_id'], 'qapd_org_approver_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign('approver_user_id', 'qapd_approver_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_approval_decisions');
    }
};
