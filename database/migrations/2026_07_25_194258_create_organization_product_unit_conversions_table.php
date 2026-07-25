<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.4: exact organization-scoped unit conversions (integer numerator/denominator).
 * No reciprocal rows are auto-created. No inventory quantities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_product_unit_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('organization_product_id');
            $table->string('from_unit');
            $table->string('to_unit');
            $table->unsignedInteger('numerator');
            $table->unsignedInteger('denominator');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['organization_product_id', 'from_unit', 'to_unit'],
                'opuc_op_from_to_uidx',
            );
            $table->index(
                ['organization_id', 'organization_product_id'],
                'opuc_org_op_idx',
            );
            $table->index(['parent_account_id', 'organization_id'], 'opuc_pa_org_idx');
            $table->index('is_active', 'opuc_active_idx');

            $table->foreign('parent_account_id', 'opuc_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'opuc_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign('organization_product_id', 'opuc_op_fk')
                ->references('id')
                ->on('organization_products')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'opuc_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'organization_product_id'], 'opuc_org_op_fk')
                ->references(['organization_id', 'id'])
                ->on('organization_products')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_product_unit_conversions');
    }
};
