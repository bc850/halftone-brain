<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A: circular Quote ↔ QuoteRevision pointer FKs (same-quote enforced).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->foreign(['id', 'current_revision_id'], 'qu_current_rev_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();

            $table->foreign(['id', 'accepted_revision_id'], 'qu_accepted_rev_fk')
                ->references(['quote_id', 'id'])
                ->on('quote_revisions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite cannot drop named foreign keys without rebuilding the table.
            return;
        }

        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropForeign('qu_current_rev_fk');
            $table->dropForeign('qu_accepted_rev_fk');
        });
    }
};
