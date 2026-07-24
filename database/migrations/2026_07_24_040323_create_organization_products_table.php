<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1A: organization-specific product availability and pricing inputs.
 * Unused by application controllers until Phase 1B/1C.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id')->constrained('parent_accounts')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->string('display_name')->nullable();
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('material_cost_micro_units')->default(0);
            $table->unsignedBigInteger('labor_cost_micro_units')->default(0);
            $table->string('overhead_mode')->default('none');
            $table->unsignedBigInteger('overhead_amount_micro_units')->default(0);
            $table->unsignedInteger('overhead_rate_basis_points')->default(0);

            $table->string('pricing_method')->default('markup');
            $table->unsignedInteger('markup_basis_points')->default(0);
            $table->unsignedInteger('target_margin_basis_points')->default(0);
            $table->unsignedBigInteger('fixed_price_cents')->nullable();
            $table->unsignedBigInteger('minimum_price_cents')->nullable();
            $table->boolean('allow_price_override')->default(false);
            $table->string('currency_code', 3)->default('USD');
            $table->unsignedInteger('pricing_version')->default(1);

            $table->timestamps();

            $table->unique(['organization_id', 'product_id'], 'op_org_product_uidx');
            $table->unique(['organization_id', 'id'], 'op_org_id_uidx');
            $table->index(['parent_account_id', 'organization_id'], 'op_pa_org_idx');
            $table->index(['parent_account_id', 'product_id'], 'op_pa_pr_idx');
            $table->index(['parent_account_id', 'id'], 'op_pa_id_idx');
            $table->index('is_available', 'op_available_idx');
            $table->index('pricing_method', 'op_pricing_method_idx');

            $table->foreign(['parent_account_id', 'organization_id'], 'op_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'product_id'], 'op_pa_pr_fk')
                ->references(['parent_account_id', 'id'])
                ->on('products')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_products');
    }
};
