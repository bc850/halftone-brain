<?php

use App\Support\Tenancy\TenantSchemaHardeningGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0E-1: composite tenant-integrity foreign keys.
 * Optional nullable relationships (vendor/category/audit org) keep MySQL NULL-skip semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchemaHardeningGuard::assertReadyOrFail();

        Schema::table('organization_companies', function (Blueprint $table): void {
            $table->foreign(['parent_account_id', 'organization_id'], 'oc_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'company_id'], 'oc_pa_co_fk')
                ->references(['parent_account_id', 'id'])
                ->on('companies')
                ->restrictOnDelete();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->foreign(['parent_account_id', 'company_id'], 'ct_pa_co_fk')
                ->references(['parent_account_id', 'id'])
                ->on('companies')
                ->restrictOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreign(['parent_account_id', 'vendor_id'], 'pr_pa_ve_fk')
                ->references(['parent_account_id', 'id'])
                ->on('vendors')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'product_category_id'], 'pr_pa_pc_fk')
                ->references(['parent_account_id', 'id'])
                ->on('product_categories')
                ->restrictOnDelete();
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'organization_company_id'], 'de_org_oc_fk')
                ->references(['organization_id', 'id'])
                ->on('organization_companies')
                ->restrictOnDelete();

            if (! $this->hasIndex('deals', 'de_pa_org_idx')) {
                $table->index(['parent_account_id', 'organization_id'], 'de_pa_org_idx');
            }

            $table->foreign(['parent_account_id', 'organization_id'], 'de_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();
        });

        Schema::table('teams', function (Blueprint $table): void {
            if (! $this->hasIndex('teams', 'tm_pa_org_idx')) {
                $table->index(['parent_account_id', 'organization_id'], 'tm_pa_org_idx');
            }

            $table->foreign(['parent_account_id', 'organization_id'], 'tm_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->foreign(['parent_account_id', 'organization_id'], 'au_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->dropCompositeForeign('audit_events', 'au_pa_org_fk', ['parent_account_id', 'organization_id']);

        $this->dropCompositeForeign('teams', 'tm_pa_org_fk', ['parent_account_id', 'organization_id']);
        Schema::table('teams', function (Blueprint $table): void {
            if ($this->hasIndex('teams', 'tm_pa_org_idx')) {
                $table->dropIndex('tm_pa_org_idx');
            }
        });

        $this->dropCompositeForeign('deals', 'de_pa_org_fk', ['parent_account_id', 'organization_id']);
        $this->dropCompositeForeign('deals', 'de_org_oc_fk', ['organization_id', 'organization_company_id']);
        Schema::table('deals', function (Blueprint $table): void {
            if ($this->hasIndex('deals', 'de_pa_org_idx')) {
                $table->dropIndex('de_pa_org_idx');
            }
        });

        $this->dropCompositeForeign('products', 'pr_pa_pc_fk', ['parent_account_id', 'product_category_id']);
        $this->dropCompositeForeign('products', 'pr_pa_ve_fk', ['parent_account_id', 'vendor_id']);
        $this->dropCompositeForeign('contacts', 'ct_pa_co_fk', ['parent_account_id', 'company_id']);
        $this->dropCompositeForeign('organization_companies', 'oc_pa_co_fk', ['parent_account_id', 'company_id']);
        $this->dropCompositeForeign('organization_companies', 'oc_pa_org_fk', ['parent_account_id', 'organization_id']);
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropCompositeForeign(string $table, string $name, array $columns): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($name, $columns): void {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $blueprint->dropForeign($name);

                return;
            }

            // SQLite cannot drop foreign keys by name.
            $blueprint->dropForeign($columns);
        });
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
