<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C.7A: append-only org source price history.
 * Records vendor package price and normalized effective per-purchase-UOM cost.
 * No updated_at. 1C.7A does not insert events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_product_source_price_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_account_id');
            $table->foreignId('organization_id');
            $table->foreignId('organization_product_source_id');
            $table->unsignedBigInteger('package_price_micro_units');
            $table->unsignedBigInteger('effective_purchase_unit_cost_micro_units');
            $table->string('currency_code', 3)->default('USD');
            $table->foreignId('actor_user_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['organization_product_source_id', 'recorded_at'],
                'opspe_source_recorded_idx',
            );
            $table->index(
                ['parent_account_id', 'organization_id'],
                'opspe_pa_org_idx',
            );

            $table->foreign('parent_account_id', 'opspe_pa_fk')
                ->references('id')
                ->on('parent_accounts')
                ->restrictOnDelete();

            $table->foreign('organization_id', 'opspe_org_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign('organization_product_source_id', 'opspe_source_fk')
                ->references('id')
                ->on('organization_product_sources')
                ->restrictOnDelete();

            $table->foreign('actor_user_id', 'opspe_actor_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign(['parent_account_id', 'organization_id'], 'opspe_pa_org_fk')
                ->references(['parent_account_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(['organization_id', 'organization_product_source_id'], 'opspe_org_source_fk')
                ->references(['organization_id', 'id'])
                ->on('organization_product_sources')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_product_source_price_events');
    }
};
