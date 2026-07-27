<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B.1: tax readiness columns on quote_revisions. No tax calculation yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_revisions', function (Blueprint $table): void {
            $table->string('tax_calculation_status')->default('pending')->after('grand_total_cents');
            $table->json('tax_snapshot_json')->nullable()->after('tax_calculation_status');
            $table->timestamp('tax_calculated_at')->nullable()->after('tax_snapshot_json');
        });
    }

    public function down(): void
    {
        Schema::table('quote_revisions', function (Blueprint $table): void {
            $table->dropColumn(['tax_calculation_status', 'tax_snapshot_json', 'tax_calculated_at']);
        });
    }
};
