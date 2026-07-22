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
            $table->foreignId('sales_owner_membership_id')
                ->nullable()
                ->constrained('memberships')
                ->nullOnDelete();
            $table->string('payment_terms')->nullable();
            $table->boolean('credit_hold')->default(false);
            $table->string('customer_number')->nullable();
            $table->string('tax_posture')->default('unknown');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'company_id']);
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'customer_number']);
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
