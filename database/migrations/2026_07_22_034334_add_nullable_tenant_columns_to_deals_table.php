<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->foreignId('parent_account_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('parent_accounts')
                ->restrictOnDelete();
            $table->foreignId('organization_company_id')
                ->nullable()
                ->after('company_id')
                ->constrained('organization_companies')
                ->restrictOnDelete();

            $table->index(['organization_id', 'organization_company_id']);
            $table->index(['parent_account_id', 'id']);
            $table->index(['organization_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropForeign(['organization_company_id']);
            $table->dropForeign(['parent_account_id']);
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id', 'organization_company_id']);
            $table->dropIndex(['parent_account_id', 'id']);
            $table->dropIndex(['organization_id', 'id']);
            $table->dropColumn(['organization_id', 'parent_account_id', 'organization_company_id']);
        });
    }
};
