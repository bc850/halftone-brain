<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('parent_account_id')
                ->nullable()
                ->after('id')
                ->constrained('parent_accounts')
                ->restrictOnDelete();

            $table->index(['parent_account_id', 'id']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('parent_account_id')
                ->nullable()
                ->after('id')
                ->constrained('parent_accounts')
                ->restrictOnDelete();

            $table->index(['parent_account_id', 'company_id']);
            $table->index(['parent_account_id', 'id']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->foreignId('parent_account_id')
                ->nullable()
                ->after('id')
                ->constrained('parent_accounts')
                ->restrictOnDelete();

            $table->index(['parent_account_id', 'id']);
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('parent_account_id')
                ->nullable()
                ->after('id')
                ->constrained('parent_accounts')
                ->restrictOnDelete();

            $table->index(['parent_account_id', 'id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('parent_account_id')
                ->nullable()
                ->after('id')
                ->constrained('parent_accounts')
                ->restrictOnDelete();

            $table->index(['parent_account_id', 'id']);
            $table->index(['parent_account_id', 'vendor_id']);
            $table->index(['parent_account_id', 'product_category_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['parent_account_id']);
            $table->dropIndex(['parent_account_id', 'id']);
            $table->dropIndex(['parent_account_id', 'vendor_id']);
            $table->dropIndex(['parent_account_id', 'product_category_id']);
            $table->dropColumn('parent_account_id');
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropForeign(['parent_account_id']);
            $table->dropIndex(['parent_account_id', 'id']);
            $table->dropColumn('parent_account_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropForeign(['parent_account_id']);
            $table->dropIndex(['parent_account_id', 'id']);
            $table->dropColumn('parent_account_id');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['parent_account_id']);
            $table->dropIndex(['parent_account_id', 'company_id']);
            $table->dropIndex(['parent_account_id', 'id']);
            $table->dropColumn('parent_account_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['parent_account_id']);
            $table->dropIndex(['parent_account_id', 'id']);
            $table->dropColumn('parent_account_id');
        });
    }
};
