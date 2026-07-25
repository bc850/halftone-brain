<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.4: organization sellable/purchasable/inventory classification and UOM overrides.
 * Defaults preserve existing finished-product catalog behavior before 1C.5 UI.
 * perpetual_internal is not a stored Phase 1 value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_products', function (Blueprint $table): void {
            $table->boolean('is_sellable')->default(true)->after('is_available');
            $table->boolean('is_purchasable')->default(false)->after('is_sellable');
            $table->string('inventory_tracking_mode')->default('none')->after('is_purchasable');
            $table->string('purchase_unit_of_measure')->nullable()->after('inventory_tracking_mode');
            $table->string('stock_unit_of_measure')->nullable()->after('purchase_unit_of_measure');
            $table->string('usage_unit_of_measure')->nullable()->after('stock_unit_of_measure');

            $table->index('is_sellable', 'op_sellable_idx');
            $table->index('is_purchasable', 'op_purchasable_idx');
            $table->index('inventory_tracking_mode', 'op_inv_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::table('organization_products', function (Blueprint $table): void {
            $table->dropIndex('op_sellable_idx');
            $table->dropIndex('op_purchasable_idx');
            $table->dropIndex('op_inv_mode_idx');
            $table->dropColumn([
                'is_sellable',
                'is_purchasable',
                'inventory_tracking_mode',
                'purchase_unit_of_measure',
                'stock_unit_of_measure',
                'usage_unit_of_measure',
            ]);
        });
    }
};
