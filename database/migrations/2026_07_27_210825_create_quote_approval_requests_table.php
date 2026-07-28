<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C.1: approval requests raised against a quote revision.
 *
 * At most one pending request may exist per revision. `pending_guard` carries the
 * revision id while the request is pending and NULL otherwise; the unique index on
 * it enforces the rule in the database because both MySQL and SQLite permit
 * repeated NULLs in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->unsignedInteger('request_version');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('pending_guard')->nullable();
            $table->json('rule_snapshot_json')->nullable();
            $table->unsignedBigInteger('requested_by_membership_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();

            $table->unique(['quote_revision_id', 'request_version'], 'qapr_rev_version_uidx');
            $table->unique(['quote_revision_id', 'id'], 'qapr_rev_id_uidx');
            $table->unique(['organization_id', 'id'], 'qapr_org_id_uidx');
            $table->unique('pending_guard', 'qapr_pending_guard_uidx');
            $table->index(['organization_id', 'status'], 'qapr_org_status_idx');
            $table->index(['quote_id', 'requested_at'], 'qapr_quote_requested_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qapr_pa_org_idx');

            $table->foreign('parent_account_id', 'qapr_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qapr_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qapr_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_id'], 'qapr_org_quote_fk')
                ->references(['organization_id', 'id'])
                ->on('quotes')
                ->restrictOnDelete();

            $table->foreign(['quote_id', 'quote_revision_id'], 'qapr_quote_rev_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'requested_by_membership_id'], 'qapr_org_requester_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign('requested_by_user_id', 'qapr_requester_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        if (! Schema::hasColumn('quote_revisions', 'current_approval_request_id')) {
            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->unsignedBigInteger('current_approval_request_id')->nullable()->after('current_tax_calculation_id');
            });

            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->foreign(['id', 'current_approval_request_id'], 'qrev_current_approval_req_fk')
                    ->references(['quote_revision_id', 'id'])
                    ->on('quote_approval_requests')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // SQLite can neither drop a named foreign key nor drop a column that one
        // references, so the pointer column stays behind there. `up()` tolerates it.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->dropForeign('qrev_current_approval_req_fk');
            });

            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->dropColumn('current_approval_request_id');
            });
        }

        Schema::dropIfExists('quote_approval_requests');
    }
};
