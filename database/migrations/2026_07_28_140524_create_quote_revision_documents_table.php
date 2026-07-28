<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2D.1: immutable generated customer-quote document snapshots.
 *
 * Created_at only — each generation attempt is a new versioned row. Private
 * storage paths never hold public URLs. Customer payload snapshots must stay
 * free of internal cost and approval metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_revision_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->string('document_type');
            $table->unsignedInteger('document_version');
            $table->string('generation_status')->default('pending');
            $table->json('customer_payload_snapshot_json')->nullable();
            $table->string('private_html_path')->nullable();
            $table->string('private_pdf_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->unsignedBigInteger('generated_by_membership_id')->nullable();
            $table->unsignedBigInteger('generated_by_user_id')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->uuid('correlation_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['quote_revision_id', 'document_type', 'document_version'], 'qrd_rev_type_version_uidx');
            $table->unique(['quote_revision_id', 'id'], 'qrd_rev_id_uidx');
            $table->unique(['organization_id', 'id'], 'qrd_org_id_uidx');
            $table->index(['quote_id', 'created_at'], 'qrd_quote_created_idx');
            $table->index(['organization_id', 'generation_status'], 'qrd_org_status_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qrd_pa_org_idx');

            $table->foreign('parent_account_id', 'qrd_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qrd_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qrd_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_id'], 'qrd_org_quote_fk')
                ->references(['organization_id', 'id'])
                ->on('quotes')
                ->restrictOnDelete();

            $table->foreign(['quote_id', 'quote_revision_id'], 'qrd_quote_rev_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'generated_by_membership_id'], 'qrd_org_actor_mem_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign('generated_by_user_id', 'qrd_actor_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        if (! Schema::hasColumn('quote_revisions', 'current_document_id')) {
            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->unsignedBigInteger('current_document_id')->nullable()->after('current_approval_request_id');
            });

            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->foreign(['id', 'current_document_id'], 'qrev_current_document_fk')
                    ->references(['quote_revision_id', 'id'])
                    ->on('quote_revision_documents')
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
                $table->dropForeign('qrev_current_document_fk');
            });

            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->dropColumn('current_document_id');
            });
        }

        Schema::dropIfExists('quote_revision_documents');
    }
};
