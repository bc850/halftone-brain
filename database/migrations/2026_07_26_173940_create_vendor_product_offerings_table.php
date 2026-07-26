<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.7A: parent-scoped vendor offerings for a Product Master.
 * No vendor price at this layer. Does not write products.vendor_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_product_offerings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('product_id');
            $table->foreignId('vendor_id');
            $table->string('vendor_sku');
            $table->text('vendor_description')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('manufacturer_part_number')->nullable();
            $table->string('product_url')->nullable();
            $table->string('purchase_uom');
            $table->unsignedBigInteger('package_quantity_scaled')->default(1_000_000);
            $table->unsignedBigInteger('minimum_order_quantity_scaled')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('discontinued_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['parent_account_id', 'vendor_id', 'vendor_sku'],
                'vpo_pa_vendor_sku_uidx',
            );
            $table->unique(
                ['parent_account_id', 'id'],
                'vpo_pa_id_uidx',
            );
            $table->index(
                ['parent_account_id', 'product_id'],
                'vpo_pa_product_idx',
            );
            $table->index(
                ['parent_account_id', 'vendor_id', 'status'],
                'vpo_pa_vendor_status_idx',
            );

            $table->foreign('parent_account_id', 'vpo_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('product_id', 'vpo_product_fk')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();

            $table->foreign('vendor_id', 'vpo_vendor_fk')
                ->references('id')
                ->on('vendors')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'product_id'], 'vpo_pa_product_fk')
                ->references(['parent_account_id', 'id'])
                ->on('products')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'vendor_id'], 'vpo_pa_vendor_fk')
                ->references(['parent_account_id', 'id'])
                ->on('vendors')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_product_offerings');
    }
};
