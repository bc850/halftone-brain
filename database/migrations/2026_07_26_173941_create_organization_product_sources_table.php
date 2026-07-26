<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.7A: organization-scoped links from OrganizationProduct to vendor offerings.
 * Org package price lives here. Does not sync OrganizationProduct.purchase_cost in 1C.7A.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_product_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('organization_product_id');
            $table->foreignId('vendor_product_offering_id');
            $table->unsignedBigInteger('current_package_price_micro_units')->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->unsignedBigInteger('price_version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['organization_product_id', 'vendor_product_offering_id'],
                'opsrc_op_offering_uidx',
            );
            // Supports composite preferred-source FK: (organization_product_id, id).
            $table->unique(
                ['organization_product_id', 'id'],
                'opsrc_op_id_uidx',
            );
            $table->unique(
                ['organization_id', 'id'],
                'opsrc_org_id_uidx',
            );
            $table->index(
                ['organization_id', 'organization_product_id', 'is_active'],
                'opsrc_org_op_active_idx',
            );
            $table->index(
                ['vendor_product_offering_id', 'is_active'],
                'opsrc_offering_active_idx',
            );
            $table->index(
                ['parent_account_id', 'organization_id'],
                'opsrc_pa_org_idx',
            );

            $table->foreign('parent_account_id', 'opsrc_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'opsrc_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign('organization_product_id', 'opsrc_op_fk')
                ->references('id')
                ->on('organization_products')
                ->restrictOnDelete();

            $table->foreign('vendor_product_offering_id', 'opsrc_offering_fk')
                ->references('id')
                ->on('vendor_product_offerings')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'opsrc_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'organization_product_id'], 'opsrc_org_op_fk')
                ->references(['organization_id', 'id'])
                ->on('organization_products')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'vendor_product_offering_id'], 'opsrc_pa_offering_fk')
                ->references(['parent_account_id', 'id'])
                ->on('vendor_product_offerings')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_product_sources');
    }
};
