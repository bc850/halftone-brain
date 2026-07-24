<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0F: drop the unused legacy team_user pivot.
 * Tenant-aware memberships live on team_memberships.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::connection()->pretending()) {
            $this->assertTeamUserEmptyOrFail();
        }

        Schema::dropIfExists('team_user');
    }

    public function down(): void
    {
        if (Schema::hasTable('team_user')) {
            return;
        }

        Schema::create('team_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'user_id'], 'team_user_team_id_user_id_unique');
        });
    }

    private function assertTeamUserEmptyOrFail(): void
    {
        if (! Schema::hasTable('team_user')) {
            throw new RuntimeException(
                'Legacy team_user drop aborted; table does not exist.'
            );
        }

        $row = DB::selectOne('select count(*) as c from team_user');

        if (! $row instanceof stdClass || ! property_exists($row, 'c') || $row->c === null) {
            throw new RuntimeException(
                'Legacy team_user drop aborted; validation query returned no result.'
            );
        }

        $count = (int) $row->c;

        if ($count > 0) {
            throw new RuntimeException(
                "Legacy team_user drop aborted; table contains {$count} row(s)."
            );
        }
    }
};
