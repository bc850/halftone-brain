<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B.1: QuoteRevision line items. Snapshots are authoritative; live catalog IDs are traceability only.
 * Quantity uses six-decimal scaled integers (×1,000,000). Selling money is integer cents. Costs are micro-units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_revision_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('quote_id');
            $table->foreignId('quote_revision_id');
            $table->unsignedInteger('position');
            $table->string('line_type');

            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('organization_product_id')->nullable();

            $table->string('sku_snapshot')->nullable();
            $table->string('name_snapshot');
            $table->text('customer_description_snapshot')->nullable();
            $table->text('internal_description_snapshot')->nullable();
            $table->string('item_kind_snapshot')->nullable();

            $table->bigInteger('quantity_scaled')->default(0);
            $table->string('uom_snapshot')->nullable();

            $table->bigInteger('calculated_unit_price_cents')->nullable();
            $table->bigInteger('final_unit_price_cents')->nullable();
            $table->bigInteger('extended_price_cents')->default(0);

            $table->string('line_discount_method')->default('none');
            $table->bigInteger('line_discount_value')->default(0);
            $table->bigInteger('line_discount_amount_cents')->default(0);
            $table->bigInteger('net_line_total_cents')->default(0);

            $table->boolean('is_taxable')->default(true);
            $table->boolean('price_override')->default(false);
            $table->string('override_reason')->nullable();
            $table->boolean('below_minimum')->default(false);
            $table->boolean('approval_required')->default(false);
            $table->json('approval_reason_json')->nullable();

            $table->bigInteger('material_cost_micro_units')->nullable();
            $table->bigInteger('labor_cost_micro_units')->nullable();
            $table->bigInteger('overhead_cost_micro_units')->nullable();
            $table->bigInteger('total_cost_micro_units')->nullable();

            $table->string('pricing_method_snapshot')->nullable();
            $table->unsignedInteger('markup_basis_points_snapshot')->nullable();
            $table->unsignedInteger('margin_basis_points_snapshot')->nullable();
            $table->unsignedInteger('pricing_version_snapshot')->nullable();
            $table->unsignedInteger('components_version_snapshot')->nullable();

            $table->json('component_cost_breakdown_json')->nullable();
            $table->json('pricing_input_snapshot_json')->nullable();
            $table->json('pricing_result_snapshot_json')->nullable();
            $table->json('configurable_input_snapshot_json')->nullable();

            $table->timestamps();

            $table->unique(['quote_revision_id', 'position'], 'qrli_rev_pos_uidx');
            $table->unique(['quote_revision_id', 'id'], 'qrli_rev_id_uidx');
            $table->unique(['organization_id', 'id'], 'qrli_org_id_uidx');
            $table->index(['organization_id', 'quote_revision_id'], 'qrli_org_rev_idx');
            $table->index(['parent_account_id', 'organization_id'], 'qrli_pa_org_idx');
            $table->index(['quote_revision_id', 'line_type'], 'qrli_rev_type_idx');

            $table->foreign('parent_account_id', 'qrli_pa_fk')
                ->references('id')->on('parent_accounts')->restrictOnDelete();
            $table->foreign('organization_id', 'qrli_org_fk')
                ->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['parent_account_id', 'organization_id'], 'qrli_pa_org_fk')
                ->references(['parent_account_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['organization_id', 'quote_id'], 'qrli_org_quote_fk')
                ->references(['organization_id', 'id'])->on('quotes')->restrictOnDelete();
            $table->foreign(['quote_id', 'quote_revision_id'], 'qrli_quote_rev_fk')
                ->references(['quote_id', 'id'])->on('quote_revisions')->restrictOnDelete();
            $table->foreign(['parent_account_id', 'product_id'], 'qrli_pa_product_fk')
                ->references(['parent_account_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['organization_id', 'organization_product_id'], 'qrli_org_op_fk')
                ->references(['organization_id', 'id'])->on('organization_products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_revision_line_items');
    }
};
