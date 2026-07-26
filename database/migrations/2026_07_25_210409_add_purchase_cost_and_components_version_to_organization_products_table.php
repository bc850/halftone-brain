<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.6A: organization purchase cost (per purchase UOM) and components concurrency version.
 * Does not reuse material_cost_micro_units. Does not backfill purchase cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_products', function (Blueprint $table): void {
            $table->unsignedBigInteger('purchase_cost_micro_units')
                ->nullable()
                ->after('material_cost_micro_units');
            $table->unsignedBigInteger('components_version')
                ->default(1)
                ->after('pricing_version');
        });
    }

    public function down(): void
    {
        Schema::table('organization_products', function (Blueprint $table): void {
            $table->dropColumn([
                'purchase_cost_micro_units',
                'components_version',
            ]);
        });
    }
};
