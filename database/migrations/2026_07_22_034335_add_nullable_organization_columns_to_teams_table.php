<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
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

            $table->unique(['organization_id', 'id']);
            $table->index(['parent_account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['parent_account_id']);
            $table->dropUnique(['organization_id', 'id']);
            $table->dropIndex(['parent_account_id', 'id']);
            $table->dropColumn(['organization_id', 'parent_account_id']);
        });
    }
};
