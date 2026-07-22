<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('parent_account_id')->constrained('parent_accounts')->restrictOnDelete();
            $table->string('lifecycle_status');
            $table->string('relationship_status');
            $table->boolean('is_flagged')->default(false);
            $table->text('flagged_reason')->nullable();
            $table->unsignedBigInteger('sales_owner_membership_id')->nullable();
            $table->string('payment_terms')->nullable();
            $table->boolean('credit_hold')->default(false);
            $table->string('customer_number')->nullable();
            $table->string('tax_posture')->default('unknown');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('sales_owner_membership_id', 'org_companies_sales_owner_fk')
                ->references('id')
                ->on('memberships')
                ->nullOnDelete();

            $table->unique(['organization_id', 'company_id']);
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'customer_number'], 'org_companies_customer_number_unique');
            $table->index(['parent_account_id', 'organization_id']);
            $table->index(['parent_account_id', 'company_id']);
            $table->index(['parent_account_id', 'id']);
            $table->index('lifecycle_status');
            $table->index('relationship_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_companies');
    }
};
