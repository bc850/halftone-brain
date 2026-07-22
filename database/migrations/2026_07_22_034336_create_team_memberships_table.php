<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('membership_id');
            $table->timestamps();

            $table->unique(['team_id', 'membership_id']);
            $table->index('organization_id');

            $table->foreign(['organization_id', 'team_id'], 'team_memberships_org_team_fk')
                ->references(['organization_id', 'id'])
                ->on('teams')
                ->cascadeOnDelete();

            $table->foreign(['organization_id', 'membership_id'], 'team_memberships_org_membership_fk')
                ->references(['organization_id', 'id'])
                ->on('memberships')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_memberships');
    }
};
