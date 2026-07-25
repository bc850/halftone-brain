<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.4: shared Product Master item kind (product / material / service).
 * Independent from product_family. Default preserves finished-product workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('item_kind')->default('product')->after('product_family');
            $table->index(['parent_account_id', 'item_kind'], 'pr_pa_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('pr_pa_kind_idx');
            $table->dropColumn('item_kind');
        });
    }
};
