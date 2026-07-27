<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B.1: immutable party identity snapshot for a QuoteRevision.
 * One row per revision. Display truth is the snapshot, not live CRM rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasUniqueIndex('contacts', 'ct_pa_id_uidx')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->unique(['parent_account_id', 'id'], 'ct_pa_id_uidx');
            });
        }

        Schema::create('quote_revision_party_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');

            $table->string('selling_organization_name');
            $table->string('selling_organization_slug');
            $table->json('selling_organization_display_json')->nullable();

            $table->foreignId('company_id');
            $table->string('customer_company_name');
            $table->foreignId('organization_company_id');
            $table->string('customer_number')->nullable();

            $table->unsignedBigInteger('primary_contact_id')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            $table->json('billing_address_json')->nullable();
            $table->json('service_address_json')->nullable();

            $table->unsignedBigInteger('salesperson_membership_id')->nullable();
            $table->unsignedBigInteger('salesperson_user_id')->nullable();
            $table->string('salesperson_name')->nullable();
            $table->string('salesperson_email')->nullable();

            $table->foreignId('preparer_membership_id');
            $table->foreignId('preparer_user_id');
            $table->string('preparer_name');
            $table->string('preparer_email')->nullable();

            $table->string('customer_po_reference')->nullable();
            $table->timestamps();

            $table->unique(['quote_revision_id'], 'qrps_revision_uidx');
            $table->unique(['quote_id', 'quote_revision_id'], 'qrps_quote_rev_uidx');
            $table->unique(['organization_id', 'id'], 'qrps_org_id_uidx');
            $table->index(['parent_account_id', 'organization_id'], 'qrps_pa_org_idx');

            $table->foreign('parent_account_id', 'qrps_pa_fk')
                ->references('id')->on('parent_accounts')->restrictOnDelete();
            $table->foreign('organization_id', 'qrps_org_fk')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['parent_account_id', 'organization_id'], 'qrps_pa_org_fk')
                ->references(['parent_account_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['organization_id', 'quote_id'], 'qrps_org_quote_fk')
                ->references(['organization_id', 'id'])->on('quotes')->restrictOnDelete();
            $table->foreign(['quote_id', 'quote_revision_id'], 'qrps_quote_rev_fk')
                ->references(['quote_id', 'id'])->on('quote_revisions')->restrictOnDelete();
            $table->foreign(['parent_account_id', 'company_id'], 'qrps_pa_company_fk')
                ->references(['parent_account_id', 'id'])->on('companies')->restrictOnDelete();
            $table->foreign(['organization_id', 'organization_company_id'], 'qrps_org_oc_fk')
                ->references(['organization_id', 'id'])->on('organization_companies')->restrictOnDelete();
            $table->foreign(['parent_account_id', 'primary_contact_id'], 'qrps_pa_contact_fk')
                ->references(['parent_account_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['organization_id', 'salesperson_membership_id'], 'qrps_org_sales_mem_fk')
                ->references(['organization_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['organization_id', 'preparer_membership_id'], 'qrps_org_prep_mem_fk')
                ->references(['organization_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign('salesperson_user_id', 'qrps_sales_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('preparer_user_id', 'qrps_prep_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_revision_party_snapshots');

        if ($this->hasUniqueIndex('contacts', 'ct_pa_id_uidx') && Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->dropUnique('ct_pa_id_uidx');
            });
        }
    }

    private function hasUniqueIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name && ($index['unique'] ?? false)) {
                return true;
            }
        }

        return false;
    }
};
