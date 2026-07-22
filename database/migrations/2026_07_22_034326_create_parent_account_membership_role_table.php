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
            $table->unsignedBigInteger('parent_account_membership_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamps();

            $table->foreign('parent_account_membership_id', 'pam_role_membership_fk')
                ->references('id')
                ->on('parent_account_memberships')
                ->cascadeOnDelete();
            $table->foreign('role_id', 'pam_role_role_fk')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();

            $table->unique(['parent_account_membership_id', 'role_id'], 'pam_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_account_membership_role');
    }
};
