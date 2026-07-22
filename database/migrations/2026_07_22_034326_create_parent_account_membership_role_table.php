<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_account_membership_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_account_membership_id')
                ->constrained('parent_account_memberships')
                ->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['parent_account_membership_id', 'role_id'], 'pam_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_account_membership_role');
    }
};
