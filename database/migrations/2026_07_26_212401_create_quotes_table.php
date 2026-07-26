<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A: org-scoped Quote identity (stable quote number).
 * Promotes deals (organization_id, id) to UNIQUE so quotes can use composite FKs.
 * current_revision_id / accepted_revision_id FKs added after quote_revisions exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureDealsOrganizationIdUnique();

        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('deal_id');
            $table->foreignId('organization_company_id');
            $table->string('quote_number');
            $table->string('lifecycle_status');
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->unsignedBigInteger('accepted_revision_id')->nullable();
            $table->foreignId('created_by_membership_id');
            $table->foreignId('sales_owner_membership_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'quote_number'], 'qu_org_number_uidx');
            $table->unique(['organization_id', 'id'], 'qu_org_id_uidx');
            $table->index(['organization_id', 'deal_id'], 'qu_org_deal_idx');
            $table->index(['organization_id', 'lifecycle_status'], 'qu_org_status_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qu_pa_org_idx');

            $table->foreign('parent_account_id', 'qu_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'qu_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'qu_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'deal_id'], 'qu_org_deal_fk')
                ->references(['organization_id', 'id'])
                ->on('deals')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'organization_company_id'], 'qu_org_oc_fk')
                ->references(['organization_id', 'id'])
                ->on('organization_companies')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'created_by_membership_id'], 'qu_org_created_mem_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'sales_owner_membership_id'], 'qu_org_sales_mem_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
        $this->restoreDealsOrganizationIdIndex();
    }

    private function ensureDealsOrganizationIdUnique(): void
    {
        if (! $this->hasIndex('deals', 'de_org_id_uidx')) {
            Schema::table('deals', function (Blueprint $table): void {
                $table->unique(['organization_id', 'id'], 'de_org_id_uidx');
            });
        }

        if ($this->hasIndex('deals', 'deals_organization_id_id_index')) {
            Schema::table('deals', function (Blueprint $table): void {
                $table->dropIndex('deals_organization_id_id_index');
            });
        }
    }

    private function restoreDealsOrganizationIdIndex(): void
    {
        if (! $this->hasIndex('deals', 'deals_organization_id_id_index')) {
            Schema::table('deals', function (Blueprint $table): void {
                $table->index(['organization_id', 'id'], 'deals_organization_id_id_index');
            });
        }

        if ($this->hasIndex('deals', 'de_org_id_uidx')) {
            Schema::table('deals', function (Blueprint $table): void {
                $table->dropUnique('de_org_id_uidx');
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
