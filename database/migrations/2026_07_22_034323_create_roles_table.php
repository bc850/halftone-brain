<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('scope');
            $table->foreignId('parent_account_id')->nullable()->constrained('parent_accounts')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->timestamps();

            $table->index('scope');
            $table->index(['parent_account_id', 'scope']);
            $table->index(['organization_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
