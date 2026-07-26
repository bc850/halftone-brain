<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.6A: fixed estimated material components for finished organization products.
 * Estimates pricing cost only — no inventory quantities, ledgers, or QuickBooks sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_product_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('organization_product_id');
            $table->foreignId('component_organization_product_id');
            $table->unsignedBigInteger('quantity_scaled');
            $table->string('usage_uom');
            $table->unsignedInteger('waste_basis_points')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['organization_product_id', 'component_organization_product_id'],
                'opc_parent_component_uidx',
            );
            $table->index(
                ['organization_product_id', 'is_active', 'sort_order'],
                'opc_parent_active_sort_idx',
            );
            $table->index(
                ['component_organization_product_id', 'is_active'],
                'opc_component_active_idx',
            );
            $table->index(['parent_account_id', 'organization_id'], 'opc_pa_org_idx');

            $table->foreign('parent_account_id', 'opc_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'opc_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign('organization_product_id', 'opc_parent_op_fk')
                ->references('id')
                ->on('organization_products')
                ->restrictOnDelete();

            $table->foreign('component_organization_product_id', 'opc_component_op_fk')
                ->references('id')
                ->on('organization_products')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'opc_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'organization_product_id'], 'opc_org_parent_fk')
                ->references(['organization_id', 'id'])
                ->on('organization_products')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'component_organization_product_id'], 'opc_org_component_fk')
                ->references(['organization_id', 'id'])
                ->on('organization_products')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_product_components');
    }
};
