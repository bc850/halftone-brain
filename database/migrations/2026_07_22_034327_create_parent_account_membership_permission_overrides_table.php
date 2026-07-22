<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_account_membership_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_account_membership_id');
            $table->unsignedBigInteger('permission_id');
            $table->string('effect');
            $table->text('reason');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('parent_account_membership_id', 'pam_perm_override_membership_fk')
                ->references('id')
                ->on('parent_account_memberships')
                ->cascadeOnDelete();
            $table->foreign('permission_id', 'pam_perm_override_permission_fk')
                ->references('id')
                ->on('permissions')
                ->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'pam_perm_override_created_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->unique(['parent_account_membership_id', 'permission_id'], 'pam_permission_override_unique');
            $table->index('effect');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_account_membership_permission_overrides');
    }
};
