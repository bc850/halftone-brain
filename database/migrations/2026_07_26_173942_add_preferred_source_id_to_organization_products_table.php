<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.7A: nullable preferred source pointer on OrganizationProduct.
 * Composite FK ensures the preferred source belongs to this OrganizationProduct.
 * Must roll back before dropping organization_product_sources.
 *
 * SQLite note: the composite preferred FK creates a circular reference with
 * organization_product_sources → organization_products. SQLite cannot toggle
 * foreign_keys inside RefreshDatabase transactions, so the composite FK is
 * MySQL-only. OrganizationProduct model validation enforces the same rule on
 * all drivers; MySQL rehearsal proves the database constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_products', function (Blueprint $table): void {
            $table->foreignId('preferred_source_id')
                ->nullable()
                ->after('components_version');

            $table->index('preferred_source_id', 'op_preferred_source_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('organization_products', function (Blueprint $table): void {
                $table->foreign(['id', 'preferred_source_id'], 'op_preferred_source_fk')
                    ->references(['organization_product_id', 'id'])
                    ->on('organization_product_sources')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('organization_products', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->dropForeign('op_preferred_source_fk');
            }

            $table->dropIndex('op_preferred_source_idx');
            $table->dropColumn('preferred_source_id');
        });
    }
};
