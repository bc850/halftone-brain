<?php

use App\Support\Tenancy\TenantSchemaHardeningGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0E-1: promote supporting (parent_account_id, id) indexes to UNIQUE
 * so composite tenant foreign keys can reference them.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchemaHardeningGuard::assertReadyOrFail();

        $this->replaceWithUnique('companies', 'companies_parent_account_id_id_index', ['parent_account_id', 'id'], 'co_pa_id_uidx');
        $this->replaceWithUnique('products', 'products_parent_account_id_id_index', ['parent_account_id', 'id'], 'pr_pa_id_uidx');
        $this->replaceWithUnique('vendors', 'vendors_parent_account_id_id_index', ['parent_account_id', 'id'], 've_pa_id_uidx');
        $this->replaceWithUnique('product_categories', 'product_categories_parent_account_id_id_index', ['parent_account_id', 'id'], 'pc_pa_id_uidx');
    }

    public function down(): void
    {
        $this->replaceUniqueWithIndex('companies', 'co_pa_id_uidx', ['parent_account_id', 'id'], 'companies_parent_account_id_id_index');
        $this->replaceUniqueWithIndex('products', 'pr_pa_id_uidx', ['parent_account_id', 'id'], 'products_parent_account_id_id_index');
        $this->replaceUniqueWithIndex('vendors', 've_pa_id_uidx', ['parent_account_id', 'id'], 'vendors_parent_account_id_id_index');
        $this->replaceUniqueWithIndex('product_categories', 'pc_pa_id_uidx', ['parent_account_id', 'id'], 'product_categories_parent_account_id_id_index');
    }

    /**
     * @param  list<string>  $columns
     */
    private function replaceWithUnique(string $table, string $existingIndex, array $columns, string $uniqueName): void
    {
        // Add UNIQUE first so MySQL can keep using a leftmost-prefix index for existing FKs,
        // then drop the redundant non-unique index.
        Schema::table($table, function (Blueprint $blueprint) use ($uniqueName, $columns): void {
            if (! $this->hasIndex($blueprint->getTable(), $uniqueName)) {
                $blueprint->unique($columns, $uniqueName);
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($existingIndex): void {
            if ($this->hasIndex($blueprint->getTable(), $existingIndex)) {
                $blueprint->dropIndex($existingIndex);
            }
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function replaceUniqueWithIndex(string $table, string $uniqueName, array $columns, string $indexName): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($indexName, $columns): void {
            if (! $this->hasIndex($blueprint->getTable(), $indexName)) {
                $blueprint->index($columns, $indexName);
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($uniqueName): void {
            if ($this->hasIndex($blueprint->getTable(), $uniqueName)) {
                $blueprint->dropUnique($uniqueName);
            }
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
