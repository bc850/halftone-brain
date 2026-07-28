<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C.1: append-only tax calculation history for a quote revision.
 *
 * Created_at only, no updated_at — every recalculation writes a new version and
 * the revision pointer moves. Money is integer cents and the applied rate is
 * parts-per-million (denominator 1,000,000).
 *
 * `certificate_evidence_snapshot_json` stores only safe fields (category, form
 * type, jurisdiction, verification status, dates, redacted reference). Raw
 * certificate numbers are never snapshotted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_revision_tax_calculations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->unsignedInteger('calculation_version');
            $table->string('outcome');
            $table->bigInteger('taxable_basis_cents')->default(0);
            $table->unsignedBigInteger('rate_ppm')->nullable();
            $table->bigInteger('tax_cents')->default(0);
            $table->json('jurisdiction_snapshot_json')->nullable();
            $table->unsignedBigInteger('organization_company_tax_certificate_id')->nullable();
            $table->json('certificate_evidence_snapshot_json')->nullable();
            $table->string('source');
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('actor_membership_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('calculator_version');
            $table->timestamp('calculated_at');
            $table->uuid('correlation_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['quote_revision_id', 'calculation_version'], 'qrtc_rev_version_uidx');
            $table->unique(['quote_revision_id', 'id'], 'qrtc_rev_id_uidx');
            $table->unique(['organization_id', 'id'], 'qrtc_org_id_uidx');
            $table->index(['quote_id', 'calculated_at'], 'qrtc_quote_calculated_idx');
            $table->index(['organization_id', 'outcome'], 'qrtc_org_outcome_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qrtc_pa_org_idx');

            $table->foreign('parent_account_id', 'qrtc_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qrtc_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qrtc_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'quote_id'], 'qrtc_org_quote_fk')
                ->references(['organization_id', 'id'])
                ->on('quotes')
                ->restrictOnDelete();

            $table->foreign(['quote_id', 'quote_revision_id'], 'qrtc_quote_rev_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'organization_company_tax_certificate_id'], 'qrtc_org_cert_fk')
                ->references(['organization_id', 'id'])
                ->on('organization_company_tax_certificates')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'actor_membership_id'], 'qrtc_org_actor_mem_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign('actor_user_id', 'qrtc_actor_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        if (! Schema::hasColumn('quote_revisions', 'current_tax_calculation_id')) {
            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->unsignedBigInteger('current_tax_calculation_id')->nullable()->after('tax_calculated_at');
            });

            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->foreign(['id', 'current_tax_calculation_id'], 'qrev_current_tax_calc_fk')
                    ->references(['quote_revision_id', 'id'])
                    ->on('quote_revision_tax_calculations')
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
                $table->dropForeign('qrev_current_tax_calc_fk');
            });

            Schema::table('quote_revisions', function (Blueprint $table): void {
                $table->dropColumn('current_tax_calculation_id');
            });
        }

        Schema::dropIfExists('quote_revision_tax_calculations');
    }
};
