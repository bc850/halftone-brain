<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1A: Product Master family + parent-scoped SKU uniqueness.
 * Retains legacy shared cost/price columns during transition.
 */
return new class extends Migration
{
    private const PARENT_SKU_UNIQUE = 'pr_pa_sku_uidx';

    private const GLOBAL_SKU_UNIQUE = 'products_sku_unique';

    private const FAMILY_INDEX = 'pr_pa_family_idx';

    public function up(): void
    {
        if (! DB::connection()->pretending()) {
            $this->assertNoWithinParentSkuDuplicates();
        }

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'product_family')) {
                $table->string('product_family')->default('other')->after('name');
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (! $this->hasIndex('products', self::FAMILY_INDEX)) {
                $table->index(['parent_account_id', 'product_family'], self::FAMILY_INDEX);
            }
        });

        // Add parent-scoped UNIQUE before dropping the global UNIQUE.
        Schema::table('products', function (Blueprint $table): void {
            if (! $this->hasIndex('products', self::PARENT_SKU_UNIQUE)) {
                $table->unique(['parent_account_id', 'sku'], self::PARENT_SKU_UNIQUE);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (DB::connection()->pretending() || $this->hasIndex('products', self::GLOBAL_SKU_UNIQUE)) {
                $table->dropUnique(self::GLOBAL_SKU_UNIQUE);
            }
        });
    }

    public function down(): void
    {
        if (! DB::connection()->pretending()) {
            $this->assertSafeToRestoreGlobalSkuUnique();
        }

        Schema::table('products', function (Blueprint $table): void {
            if (DB::connection()->pretending() || ! $this->hasIndex('products', self::GLOBAL_SKU_UNIQUE)) {
                $table->unique('sku', self::GLOBAL_SKU_UNIQUE);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (DB::connection()->pretending() || $this->hasIndex('products', self::PARENT_SKU_UNIQUE)) {
                $table->dropUnique(self::PARENT_SKU_UNIQUE);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (DB::connection()->pretending() || $this->hasIndex('products', self::FAMILY_INDEX)) {
                $table->dropIndex(self::FAMILY_INDEX);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'product_family')) {
                $table->dropColumn('product_family');
            }
        });
    }

    private function assertNoWithinParentSkuDuplicates(): void
    {
        $row = DB::selectOne(
            'select count(*) as c from (
                select parent_account_id, sku
                from products
                group by parent_account_id, sku
                having count(*) > 1
            ) duplicates'
        );

        if (! $row instanceof stdClass || ! property_exists($row, 'c') || $row->c === null) {
            throw new RuntimeException(
                'Phase 1A product SKU cutover aborted; validation query returned no result.'
            );
        }

        if ((int) $row->c > 0) {
            throw new RuntimeException(
                'Phase 1A product SKU cutover aborted; duplicate SKUs exist within a parent account.'
            );
        }
    }

    private function assertSafeToRestoreGlobalSkuUnique(): void
    {
        $row = DB::selectOne(
            'select count(*) as c from (
                select sku
                from products
                group by sku
                having count(*) > 1
            ) duplicates'
        );

        if (! $row instanceof stdClass || ! property_exists($row, 'c') || $row->c === null) {
            throw new RuntimeException(
                'Phase 1A SKU uniqueness rollback aborted; validation query returned no result.'
            );
        }

        if ((int) $row->c > 0) {
            throw new RuntimeException(
                'Phase 1A SKU uniqueness rollback aborted; duplicate SKUs across parents prevent restoring global uniqueness.'
            );
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
