<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.7D / 1C.7D.1: retire obsolete products.vendor_id and products.vendor_sku.
 *
 * Product Master identity is vendor-independent. Vendor relationships and supplier
 * item numbers live in vendor_product_offerings; organization pricing lives in
 * organization_product_sources.
 *
 * Fail-closed: aborts if any non-null products.vendor_id or nonblank products.vendor_sku exists.
 * Pretend-safe: skips result-dependent validation during migrate --pretend.
 *
 * Exact MySQL constraint/index names (Herd primary / rehearsal clones):
 * - FK products_vendor_id_foreign → vendors.id (ON DELETE SET NULL)
 * - FK pr_pa_ve_fk → vendors(parent_account_id, id) (ON DELETE RESTRICT)
 * - INDEX products_vendor_id_index (vendor_id)
 * - INDEX products_parent_account_id_vendor_id_index (parent_account_id, vendor_id)
 *
 * Column order on primary: sku → vendor_sku → vendor_id → product_category_id.
 */
return new class extends Migration
{
    private const VENDOR_ID = 'vendor_id';

    private const VENDOR_SKU = 'vendor_sku';

    private const SIMPLE_FOREIGN = 'products_vendor_id_foreign';

    private const COMPOSITE_FOREIGN = 'pr_pa_ve_fk';

    private const SIMPLE_INDEX = 'products_vendor_id_index';

    private const COMPOSITE_INDEX = 'products_parent_account_id_vendor_id_index';

    public function up(): void
    {
        if (! DB::connection()->pretending()) {
            $this->assertLegacyVendorColumnsSafeToDropOrFail();
        }

        if (DB::connection()->pretending()) {
            $this->emitIntendedDropSqlForPretend();

            return;
        }

        if (! Schema::hasColumn('products', self::VENDOR_ID) && ! Schema::hasColumn('products', self::VENDOR_SKU)) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->dropOnSqlite();

            return;
        }

        $this->dropOnMysql();
    }

    /**
     * Emit the intended drop statements during migrate --pretend.
     * Uses column-list foreign key drops so pretend works on SQLite and MySQL.
     */
    private function emitIntendedDropSqlForPretend(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['parent_account_id', self::VENDOR_ID]);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign([self::VENDOR_ID]);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(self::COMPOSITE_INDEX);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(self::SIMPLE_INDEX);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(self::VENDOR_ID);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(self::VENDOR_SKU);
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->restoreOnSqlite();

            return;
        }

        $this->restoreOnMysql();
    }

    private function dropOnMysql(): void
    {
        if (Schema::hasColumn('products', self::VENDOR_ID)) {
            foreach ($this->mysqlForeignKeyNamesForPretendOrDiscovery() as $foreignName) {
                if (! DB::connection()->pretending() && ! $this->hasForeignName('products', $foreignName)) {
                    continue;
                }

                Schema::table('products', function (Blueprint $table) use ($foreignName): void {
                    $table->dropForeign($foreignName);
                });
            }

            foreach ([self::COMPOSITE_INDEX, self::SIMPLE_INDEX] as $indexName) {
                if (! DB::connection()->pretending() && ! $this->hasIndexName('products', $indexName)) {
                    continue;
                }

                Schema::table('products', function (Blueprint $table) use ($indexName): void {
                    $table->dropIndex($indexName);
                });
            }

            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn(self::VENDOR_ID);
            });
        }

        if (Schema::hasColumn('products', self::VENDOR_SKU) || DB::connection()->pretending()) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn(self::VENDOR_SKU);
            });
        }
    }

    private function dropOnSqlite(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            if (Schema::hasColumn('products', self::VENDOR_ID)) {
                foreach (Schema::getForeignKeys('products') as $foreign) {
                    $columns = $foreign['columns'] ?? [];
                    if (! in_array(self::VENDOR_ID, $columns, true)) {
                        continue;
                    }

                    Schema::table('products', function (Blueprint $table) use ($columns): void {
                        $table->dropForeign($columns);
                    });
                }

                foreach (Schema::getIndexes('products') as $index) {
                    $columns = $index['columns'] ?? [];
                    $name = $index['name'] ?? null;
                    if (! is_string($name) || $name === 'primary' || ! in_array(self::VENDOR_ID, $columns, true)) {
                        continue;
                    }

                    Schema::table('products', function (Blueprint $table) use ($name): void {
                        $table->dropIndex($name);
                    });
                }

                Schema::table('products', function (Blueprint $table): void {
                    $table->dropColumn(self::VENDOR_ID);
                });
            }

            if (Schema::hasColumn('products', self::VENDOR_SKU)) {
                Schema::table('products', function (Blueprint $table): void {
                    $table->dropColumn(self::VENDOR_SKU);
                });
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function restoreOnMysql(): void
    {
        if (! Schema::hasColumn('products', self::VENDOR_SKU)) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string(self::VENDOR_SKU)->nullable()->after('sku');
            });
        }

        if (! Schema::hasColumn('products', self::VENDOR_ID)) {
            Schema::table('products', function (Blueprint $table): void {
                $table->unsignedBigInteger(self::VENDOR_ID)->nullable()->after(self::VENDOR_SKU);
            });

            Schema::table('products', function (Blueprint $table): void {
                $table->index(self::VENDOR_ID, self::SIMPLE_INDEX);
                $table->index(['parent_account_id', self::VENDOR_ID], self::COMPOSITE_INDEX);
            });

            Schema::table('products', function (Blueprint $table): void {
                $table->foreign(self::VENDOR_ID, self::SIMPLE_FOREIGN)
                    ->references('id')
                    ->on('vendors')
                    ->nullOnDelete();
            });

            Schema::table('products', function (Blueprint $table): void {
                $table->foreign(['parent_account_id', self::VENDOR_ID], self::COMPOSITE_FOREIGN)
                    ->references(['parent_account_id', 'id'])
                    ->on('vendors')
                    ->restrictOnDelete();
            });
        }
    }

    private function restoreOnSqlite(): void
    {
        // ADD COLUMN avoids rebuilding products (child FKs remain intact under RefreshDatabase).
        if (! Schema::hasColumn('products', self::VENDOR_SKU)) {
            DB::statement('alter table "products" add column "vendor_sku" varchar');
        }

        if (! Schema::hasColumn('products', self::VENDOR_ID)) {
            DB::statement('alter table "products" add column "vendor_id" integer');

            Schema::table('products', function (Blueprint $table): void {
                $table->index(self::VENDOR_ID, self::SIMPLE_INDEX);
                $table->index(['parent_account_id', self::VENDOR_ID], self::COMPOSITE_INDEX);
            });
        }

        // SQLite cannot add foreign keys without rebuilding the table; MySQL down() restores them.
    }

    /**
     * @return list<string>
     */
    private function mysqlForeignKeyNamesForPretendOrDiscovery(): array
    {
        if (DB::connection()->pretending()) {
            return [self::COMPOSITE_FOREIGN, self::SIMPLE_FOREIGN];
        }

        $names = [];

        foreach (Schema::getForeignKeys('products') as $foreign) {
            $columns = $foreign['columns'] ?? [];
            if (in_array(self::VENDOR_ID, $columns, true) && isset($foreign['name']) && is_string($foreign['name'])) {
                $names[] = $foreign['name'];
            }
        }

        usort($names, function (string $a, string $b): int {
            if ($a === self::COMPOSITE_FOREIGN) {
                return -1;
            }
            if ($b === self::COMPOSITE_FOREIGN) {
                return 1;
            }

            return strcmp($a, $b);
        });

        return array_values(array_unique($names));
    }

    private function hasForeignName(string $table, string $name): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if (($foreign['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function hasIndexName(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function assertLegacyVendorColumnsSafeToDropOrFail(): void
    {
        if (! Schema::hasTable('products')) {
            throw new RuntimeException(
                'Legacy products vendor columns drop aborted; products table does not exist.'
            );
        }

        if (! Schema::hasColumn('products', self::VENDOR_ID)) {
            throw new RuntimeException(
                'Legacy products vendor columns drop aborted; vendor_id column does not exist.'
            );
        }

        if (! Schema::hasColumn('products', self::VENDOR_SKU)) {
            throw new RuntimeException(
                'Legacy products vendor columns drop aborted; vendor_sku column does not exist.'
            );
        }

        $vendorIdRow = DB::selectOne('select count(*) as c from products where vendor_id is not null');

        if (! $vendorIdRow instanceof stdClass || ! property_exists($vendorIdRow, 'c') || $vendorIdRow->c === null) {
            throw new RuntimeException(
                'Legacy products vendor columns drop aborted; vendor_id validation query returned no result.'
            );
        }

        $vendorSkuRow = DB::selectOne(
            "select count(*) as c from products where vendor_sku is not null and trim(vendor_sku) <> ''"
        );

        if (! $vendorSkuRow instanceof stdClass || ! property_exists($vendorSkuRow, 'c') || $vendorSkuRow->c === null) {
            throw new RuntimeException(
                'Legacy products vendor columns drop aborted; vendor_sku validation query returned no result.'
            );
        }

        $vendorIdCount = (int) $vendorIdRow->c;
        $vendorSkuCount = (int) $vendorSkuRow->c;

        if ($vendorIdCount > 0 || $vendorSkuCount > 0) {
            throw new RuntimeException(
                "Legacy products vendor columns drop aborted; {$vendorIdCount} product(s) still reference a vendor_id and {$vendorSkuCount} product(s) still have a vendor_sku."
            );
        }
    }
};
