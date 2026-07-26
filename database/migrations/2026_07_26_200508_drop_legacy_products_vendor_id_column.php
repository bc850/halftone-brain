<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.7D: retire obsolete products.vendor_id.
 *
 * Product Master identity is vendor-independent. Vendor relationships live in
 * vendor_product_offerings; organization pricing lives in organization_product_sources.
 *
 * Fail-closed: aborts if any non-null products.vendor_id exists.
 * Pretend-safe: skips result-dependent validation during migrate --pretend.
 *
 * Exact MySQL constraint/index names (Herd primary / rehearsal clones):
 * - FK products_vendor_id_foreign → vendors.id (ON DELETE SET NULL)
 * - FK pr_pa_ve_fk → vendors(parent_account_id, id) (ON DELETE RESTRICT)
 * - INDEX products_vendor_id_index (vendor_id)
 * - INDEX products_parent_account_id_vendor_id_index (parent_account_id, vendor_id)
 */
return new class extends Migration
{
    private const COLUMN = 'vendor_id';

    private const SIMPLE_FOREIGN = 'products_vendor_id_foreign';

    private const COMPOSITE_FOREIGN = 'pr_pa_ve_fk';

    private const SIMPLE_INDEX = 'products_vendor_id_index';

    private const COMPOSITE_INDEX = 'products_parent_account_id_vendor_id_index';

    public function up(): void
    {
        if (! DB::connection()->pretending()) {
            $this->assertLegacyVendorIdSafeToDropOrFail();
        }

        if (! Schema::hasColumn('products', self::COLUMN)) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->dropOnSqlite();

            return;
        }

        $this->dropOnMysql();
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', self::COLUMN)) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // ADD COLUMN avoids rebuilding products (child FKs remain intact under RefreshDatabase).
            DB::statement('alter table "products" add column "vendor_id" integer');

            Schema::table('products', function (Blueprint $table): void {
                $table->index(self::COLUMN, self::SIMPLE_INDEX);
                $table->index(['parent_account_id', self::COLUMN], self::COMPOSITE_INDEX);
            });

            // SQLite cannot add foreign keys without rebuilding the table; MySQL down() restores them.
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger(self::COLUMN)->nullable()->after('vendor_sku');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index(self::COLUMN, self::SIMPLE_INDEX);
            $table->index(['parent_account_id', self::COLUMN], self::COMPOSITE_INDEX);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreign(self::COLUMN, self::SIMPLE_FOREIGN)
                ->references('id')
                ->on('vendors')
                ->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreign(['parent_account_id', self::COLUMN], self::COMPOSITE_FOREIGN)
                ->references(['parent_account_id', 'id'])
                ->on('vendors')
                ->restrictOnDelete();
        });
    }

    private function dropOnMysql(): void
    {
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
            $table->dropColumn(self::COLUMN);
        });
    }

    private function dropOnSqlite(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            // Drop every foreign key that includes vendor_id (simple + composite).
            foreach (Schema::getForeignKeys('products') as $foreign) {
                $columns = $foreign['columns'] ?? [];
                if (! in_array(self::COLUMN, $columns, true)) {
                    continue;
                }

                Schema::table('products', function (Blueprint $table) use ($columns): void {
                    $table->dropForeign($columns);
                });
            }

            // Drop indexes that include vendor_id before the column itself.
            foreach (Schema::getIndexes('products') as $index) {
                $columns = $index['columns'] ?? [];
                $name = $index['name'] ?? null;
                if (! is_string($name) || $name === 'primary' || ! in_array(self::COLUMN, $columns, true)) {
                    continue;
                }

                Schema::table('products', function (Blueprint $table) use ($name): void {
                    $table->dropIndex($name);
                });
            }

            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn(self::COLUMN);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
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
            if (in_array(self::COLUMN, $columns, true) && isset($foreign['name']) && is_string($foreign['name'])) {
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

    private function assertLegacyVendorIdSafeToDropOrFail(): void
    {
        if (! Schema::hasTable('products')) {
            throw new RuntimeException(
                'Legacy products.vendor_id drop aborted; products table does not exist.'
            );
        }

        if (! Schema::hasColumn('products', self::COLUMN)) {
            throw new RuntimeException(
                'Legacy products.vendor_id drop aborted; column does not exist.'
            );
        }

        $row = DB::selectOne('select count(*) as c from products where vendor_id is not null');

        if (! $row instanceof stdClass || ! property_exists($row, 'c') || $row->c === null) {
            throw new RuntimeException(
                'Legacy products.vendor_id drop aborted; validation query returned no result.'
            );
        }

        $count = (int) $row->c;

        if ($count > 0) {
            throw new RuntimeException(
                "Legacy products.vendor_id drop aborted; {$count} product(s) still reference a vendor."
            );
        }
    }
};
