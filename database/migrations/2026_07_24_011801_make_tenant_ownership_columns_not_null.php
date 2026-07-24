<?php

use App\Support\Tenancy\TenantSchemaHardeningGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0E-1: require tenant ownership columns (preserve unsigned bigint types).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchemaHardeningGuard::assertReadyOrFail();

        $this->setNullable('companies', 'parent_account_id', false);
        $this->setNullable('contacts', 'parent_account_id', false);
        $this->setNullable('vendors', 'parent_account_id', false);
        $this->setNullable('product_categories', 'parent_account_id', false);
        $this->setNullable('products', 'parent_account_id', false);
        $this->setNullable('deals', 'parent_account_id', false);
        $this->setNullable('deals', 'organization_id', false);
        $this->setNullable('deals', 'organization_company_id', false);
        $this->setNullable('teams', 'parent_account_id', false);
        $this->setNullable('teams', 'organization_id', false);
    }

    public function down(): void
    {
        $this->setNullable('teams', 'organization_id', true);
        $this->setNullable('teams', 'parent_account_id', true);
        $this->setNullable('deals', 'organization_company_id', true);
        $this->setNullable('deals', 'organization_id', true);
        $this->setNullable('deals', 'parent_account_id', true);
        $this->setNullable('products', 'parent_account_id', true);
        $this->setNullable('product_categories', 'parent_account_id', true);
        $this->setNullable('vendors', 'parent_account_id', true);
        $this->setNullable('contacts', 'parent_account_id', true);
        $this->setNullable('companies', 'parent_account_id', true);
    }

    private function setNullable(string $table, string $column, bool $nullable): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $nullSql = $nullable ? 'NULL' : 'NOT NULL';
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` BIGINT UNSIGNED {$nullSql}");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $nullable): void {
            $blueprint->unsignedBigInteger($column)->nullable($nullable)->change();
        });
    }
};
