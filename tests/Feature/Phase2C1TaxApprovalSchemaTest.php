<?php

use App\Enums\QuoteApprovalDecisionType;
use App\Enums\QuoteApprovalRequestStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\TaxCertificateVerificationStatus;
use App\Enums\TaxExemptionCategory;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompanyTaxCertificate;
use App\Models\OrganizationTaxProfile;
use App\Models\OrganizationTaxRate;
use App\Models\QuoteApprovalDecision;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionTaxCalculation;
use App\Support\Quotes\ImmutableQuoteRevisionException;
use App\Support\Quotes\QuoteApprovalInvalidationContract;
use App\Support\Quotes\QuoteRevisionTaxGuard;
use App\Support\Tax\OrganizationTaxRateService;
use App\Support\Tax\OverlappingTaxRateException;
use App\Support\Tenancy\RbacDefinitions;
use Database\Factories\QuoteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const PHASE_2C1_MIGRATIONS = [
    '2026_07_27_210821_create_organization_tax_profiles_table',
    '2026_07_27_210822_create_organization_tax_rates_table',
    '2026_07_27_210823_create_organization_company_tax_certificates_table',
    '2026_07_27_210824_create_quote_revision_tax_calculations_table',
    '2026_07_27_210825_create_quote_approval_requests_table',
    '2026_07_27_210826_create_quote_approval_decisions_table',
];

const PHASE_2C1_TABLES = [
    'organization_tax_profiles',
    'organization_tax_rates',
    'organization_company_tax_certificates',
    'quote_revision_tax_calculations',
    'quote_approval_requests',
    'quote_approval_decisions',
];

function phase2c1HasIndex(string $table, string $indexName, bool $unique = false): bool
{
    foreach (Schema::getIndexes($table) as $index) {
        if (($index['name'] ?? null) !== $indexName) {
            continue;
        }

        if ($unique && ! ($index['unique'] ?? false)) {
            return false;
        }

        return true;
    }

    return false;
}

function phase2c1HasForeign(string $table, string $name, array $columns, string $foreignTable): bool
{
    $driver = Schema::getConnection()->getDriverName();

    foreach (Schema::getForeignKeys($table) as $foreign) {
        if (($foreign['columns'] ?? []) !== $columns || ($foreign['foreign_table'] ?? null) !== $foreignTable) {
            continue;
        }

        if ($driver === 'mysql') {
            return ($foreign['name'] ?? null) === $name;
        }

        return true;
    }

    return false;
}

function phase2c1ForeignOnDelete(string $table, array $columns, string $foreignTable): ?string
{
    foreach (Schema::getForeignKeys($table) as $foreign) {
        if (($foreign['columns'] ?? []) !== $columns || ($foreign['foreign_table'] ?? null) !== $foreignTable) {
            continue;
        }

        return strtolower((string) ($foreign['on_delete'] ?? ''));
    }

    return null;
}

function phase2c1Rollback(): void
{
    if (Schema::getConnection()->getDriverName() === 'sqlite') {
        // SQLite cannot drop a column that a named foreign key references, so the
        // pointer columns are removed by rebuilding rather than through down().
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('integration_outbox');
        Schema::dropIfExists('quote_customer_response_events');
        Schema::dropIfExists('quote_customer_access_tokens');
        Schema::dropIfExists('quote_delivery_events');
        Schema::dropIfExists('quote_deliveries');
        Schema::dropIfExists('quote_revision_documents');
        Schema::dropIfExists('quote_approval_decisions');
        Schema::dropIfExists('quote_approval_requests');
        Schema::dropIfExists('quote_revision_tax_calculations');
        Schema::dropIfExists('organization_company_tax_certificates');
        Schema::dropIfExists('organization_tax_rates');
        Schema::dropIfExists('organization_tax_profiles');
        phase2c1DropRevisionPointerColumns();
        Schema::enableForeignKeyConstraints();
        DB::table('migrations')->whereIn('migration', [
            ...PHASE_2C1_MIGRATIONS,
            '2026_07_28_140524_create_quote_revision_documents_table',
            '2026_07_28_140525_create_quote_deliveries_table',
            '2026_07_28_140526_create_quote_delivery_events_table',
            '2026_07_28_140527_create_quote_customer_access_tokens_table',
            '2026_07_28_140528_create_quote_customer_response_events_table',
            '2026_07_28_140529_create_integration_outbox_table',
        ])->delete();

        return;
    }

    // Phase 2D.1 (6) + Phase 2C.1 (6).
    Artisan::call('migrate:rollback', ['--step' => 12, '--force' => true]);
}

/**
 * SQLite refuses to drop a column that a foreign key names, so the pointer columns
 * come off by rebuilding quote_revisions from its own migrations and restoring the
 * rows.
 */
function phase2c1DropRevisionPointerColumns(): void
{
    $keptColumns = array_flip(array_diff(
        Schema::getColumnListing('quote_revisions'),
        ['current_tax_calculation_id', 'current_approval_request_id', 'current_document_id'],
    ));

    $rows = DB::table('quote_revisions')->get()->map(
        fn (object $row): array => array_intersect_key((array) $row, $keptColumns)
    )->all();

    Schema::drop('quote_revisions');

    foreach ([
        '2026_07_26_212402_create_quote_revisions_table.php',
        '2026_07_27_020724_add_tax_readiness_columns_to_quote_revisions_table.php',
    ] as $migrationFile) {
        (require database_path('migrations/'.$migrationFile))->up();
    }

    foreach (array_chunk($rows, 50) as $chunk) {
        DB::table('quote_revisions')->insert($chunk);
    }
}

test('phase 2c1 tables exist with expected unique indexes and append-only shape', function () {
    foreach (PHASE_2C1_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(phase2c1HasIndex('organization_tax_profiles', 'otp_org_uidx', unique: true))->toBeTrue()
        ->and(phase2c1HasIndex('organization_tax_rates', 'otr_org_juris_from_idx'))->toBeTrue()
        ->and(phase2c1HasIndex('organization_company_tax_certificates', 'octc_org_id_uidx', unique: true))->toBeTrue()
        ->and(phase2c1HasIndex('quote_revision_tax_calculations', 'qrtc_rev_version_uidx', unique: true))->toBeTrue()
        ->and(phase2c1HasIndex('quote_revision_tax_calculations', 'qrtc_rev_id_uidx', unique: true))->toBeTrue()
        ->and(phase2c1HasIndex('quote_approval_requests', 'qapr_rev_version_uidx', unique: true))->toBeTrue()
        ->and(phase2c1HasIndex('quote_approval_requests', 'qapr_pending_guard_uidx', unique: true))->toBeTrue()
        ->and(phase2c1HasIndex('quote_approval_decisions', 'qapd_org_id_uidx', unique: true))->toBeTrue()
        ->and(Schema::hasColumn('quote_revision_tax_calculations', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('quote_approval_decisions', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumns('quote_revisions', [
            'current_tax_calculation_id',
            'current_approval_request_id',
        ]))->toBeTrue();
});

test('phase 2c1 composite FKs are tenant-safe and restrict deletes', function () {
    expect(phase2c1HasForeign('organization_tax_profiles', 'otp_pa_org_fk', ['parent_account_id', 'organization_id'], 'organizations'))->toBeTrue()
        ->and(phase2c1HasForeign('organization_company_tax_certificates', 'octc_org_company_fk', ['organization_id', 'organization_company_id'], 'organization_companies'))->toBeTrue()
        ->and(phase2c1HasForeign('quote_revision_tax_calculations', 'qrtc_quote_rev_fk', ['quote_id', 'quote_revision_id'], 'quote_revisions'))->toBeTrue()
        ->and(phase2c1HasForeign('quote_revision_tax_calculations', 'qrtc_org_cert_fk', ['organization_id', 'organization_company_tax_certificate_id'], 'organization_company_tax_certificates'))->toBeTrue()
        ->and(phase2c1HasForeign('quote_approval_requests', 'qapr_quote_rev_fk', ['quote_id', 'quote_revision_id'], 'quote_revisions'))->toBeTrue()
        ->and(phase2c1HasForeign('quote_approval_decisions', 'qapd_org_request_fk', ['organization_id', 'quote_approval_request_id'], 'quote_approval_requests'))->toBeTrue()
        ->and(phase2c1ForeignOnDelete('organization_tax_rates', ['organization_id'], 'organizations'))->toBe('restrict')
        ->and(phase2c1ForeignOnDelete('quote_revision_tax_calculations', ['quote_id', 'quote_revision_id'], 'quote_revisions'))->toBe('restrict')
        ->and(phase2c1ForeignOnDelete('quote_approval_decisions', ['organization_id', 'quote_approval_request_id'], 'quote_approval_requests'))->toBe('restrict');
});

test('phase 2c1 revision pointer foreign keys enforce same-revision rows', function () {
    expect(phase2c1HasForeign('quote_revisions', 'qrev_current_tax_calc_fk', ['id', 'current_tax_calculation_id'], 'quote_revision_tax_calculations'))->toBeTrue()
        ->and(phase2c1HasForeign('quote_revisions', 'qrev_current_approval_req_fk', ['id', 'current_approval_request_id'], 'quote_approval_requests'))->toBeTrue();

    $quoteA = QuoteFactory::createForDeal();
    $quoteB = QuoteFactory::createForDeal();

    $calculationForB = QuoteRevisionTaxCalculation::factory()->create([
        'quote_revision_id' => $quoteB->current_revision_id,
    ]);

    expect(fn () => DB::table('quote_revisions')->where('id', $quoteA->current_revision_id)->update([
        'current_tax_calculation_id' => $calculationForB->id,
    ]))->toThrow(QueryException::class);

    $ownCalculation = QuoteRevisionTaxCalculation::factory()->create([
        'quote_revision_id' => $quoteA->current_revision_id,
    ]);

    QuoteRevision::$allowLifecycleMutation = true;
    $quoteA->currentRevision->forceFill(['current_tax_calculation_id' => $ownCalculation->id])->save();
    QuoteRevision::$allowLifecycleMutation = false;

    expect($quoteA->currentRevision->fresh()->currentTaxCalculation?->id)->toBe($ownCalculation->id);
});

test('phase 2c1 an organization has at most one tax profile', function () {
    $organization = Organization::factory()->create();

    $profile = OrganizationTaxProfile::factory()->create(['organization_id' => $organization->id]);

    expect($profile->parent_account_id)->toBe($organization->parent_account_id)
        ->and($organization->fresh()->taxProfile?->id)->toBe($profile->id)
        ->and($profile->sourcing_strategy->value)->toBe('delivery')
        ->and($profile->tax_calculation_enabled)->toBeTrue();

    expect(fn () => OrganizationTaxProfile::factory()->create(['organization_id' => $organization->id]))
        ->toThrow(QueryException::class);
});

test('phase 2c1 tax rate overlap is rejected by the service, not the database', function () {
    $organization = Organization::factory()->create();
    $service = new OrganizationTaxRateService;

    OrganizationTaxRate::factory()->create([
        'organization_id' => $organization->id,
        'jurisdiction_code' => 'ga-fulton',
        'rate_ppm' => 80_000,
        'effective_from' => '2026-01-01',
        'effective_through' => '2026-06-30',
    ]);

    expect(fn () => $service->assertNoOverlap($organization, 'ga-fulton', '2026-06-30', '2026-12-31'))
        ->toThrow(OverlappingTaxRateException::class, 'overlaps existing rate');

    expect(fn () => $service->assertNoOverlap($organization, 'ga-fulton', '2026-03-01'))
        ->toThrow(OverlappingTaxRateException::class);

    expect(fn () => $service->assertNoOverlap($organization, 'ga-fulton', '2026-12-31', '2026-01-01'))
        ->toThrow(OverlappingTaxRateException::class, 'cannot precede');

    // Abutting periods and other jurisdictions are fine.
    $service->assertNoOverlap($organization, 'ga-fulton', '2026-07-01');
    $service->assertNoOverlap($organization, 'ga-dekalb', '2026-01-01');

    $successor = OrganizationTaxRate::factory()->create([
        'organization_id' => $organization->id,
        'jurisdiction_code' => 'ga-fulton',
        'rate_ppm' => 85_000,
        'effective_from' => '2026-07-01',
        'effective_through' => null,
    ]);

    expect($service->selectEffectiveRate($organization, 'ga-fulton', '2026-03-15')?->rate_ppm)->toBe(80_000)
        ->and($service->selectEffectiveRate($organization, 'ga-fulton', '2026-09-15')?->id)->toBe($successor->id)
        ->and($service->selectEffectiveRate($organization, 'ga-fulton', '2025-12-31'))->toBeNull()
        ->and($service->selectEffectiveRate($organization, 'ga-cobb', '2026-09-15'))->toBeNull();

    $successor->update(['is_active' => false]);
    expect($service->selectEffectiveRate($organization, 'ga-fulton', '2026-09-15'))->toBeNull();

    // Editing a row must not report the row itself as an overlap.
    $service->assertNoOverlap($organization, 'ga-fulton', '2026-07-01', null, $successor->id);
});

test('phase 2c1 certificates stay inside their tenant and hide numbers from snapshots', function () {
    $quote = QuoteFactory::createForDeal();

    $certificate = OrganizationCompanyTaxCertificate::factory()->verified()->create([
        'organization_company_id' => $quote->organization_company_id,
        'exemption_category' => TaxExemptionCategory::QualifyingNonprofit,
        'certificate_number' => 'CERT-SENSITIVE-1',
    ]);

    expect($certificate->organization_id)->toBe($quote->organization_id)
        ->and($quote->organizationCompany->taxCertificates)->toHaveCount(1)
        ->and($certificate->verification_status)->toBe(TaxCertificateVerificationStatus::Verified);

    $snapshot = $certificate->toEvidenceSnapshot();

    expect($snapshot)->not->toHaveKey('certificate_number')
        ->and($snapshot)->not->toHaveKey('internal_notes')
        ->and(json_encode($snapshot))->not->toContain('CERT-SENSITIVE-1')
        ->and($snapshot['certificate_reference'])->toBe('certificate:'.$certificate->id);

    $otherOrganization = Organization::factory()->create();

    expect(fn () => OrganizationCompanyTaxCertificate::factory()->create([
        'organization_company_id' => $quote->organization_company_id,
        'organization_id' => $otherOrganization->id,
        'parent_account_id' => $otherOrganization->parent_account_id,
    ]))->toThrow(QueryException::class);
});

test('phase 2c1 tax calculations are append-only and versioned per revision', function () {
    $quote = QuoteFactory::createForDeal();
    $revisionId = $quote->current_revision_id;

    $first = QuoteRevisionTaxCalculation::factory()->create([
        'quote_revision_id' => $revisionId,
        'calculation_version' => 1,
    ]);

    expect(fn () => $first->update(['tax_cents' => 1]))
        ->toThrow(LogicException::class, 'append-only');

    expect(fn () => $first->delete())
        ->toThrow(LogicException::class, 'append-only');

    expect(fn () => QuoteRevisionTaxCalculation::factory()->create([
        'quote_revision_id' => $revisionId,
        'calculation_version' => 1,
    ]))->toThrow(QueryException::class);

    $second = QuoteRevisionTaxCalculation::factory()->reviewRequired()->create([
        'quote_revision_id' => $revisionId,
        'calculation_version' => 2,
    ]);

    expect(QuoteRevision::query()->findOrFail($revisionId)->taxCalculations->pluck('id')->all())
        ->toBe([$first->id, $second->id]);
});

test('phase 2c1 only one approval request may be pending per revision', function () {
    $quote = QuoteFactory::createForDeal();
    $revision = $quote->currentRevision;

    $first = QuoteApprovalRequest::factory()->create([
        'quote_revision_id' => $revision->id,
        'request_version' => 1,
    ]);

    expect($first->pending_guard)->toBe($revision->id);

    expect(fn () => QuoteApprovalRequest::factory()->create([
        'quote_revision_id' => $revision->id,
        'request_version' => 2,
    ]))->toThrow(QueryException::class);

    $superseded = (new QuoteApprovalInvalidationContract)->markPendingRequestsSuperseded($revision);

    expect($superseded)->toBe(1)
        ->and($first->fresh()->status)->toBe(QuoteApprovalRequestStatus::Superseded)
        ->and($first->fresh()->pending_guard)->toBeNull()
        ->and($first->fresh()->resolved_at)->not->toBeNull();

    $second = QuoteApprovalRequest::factory()->create([
        'quote_revision_id' => $revision->id,
        'request_version' => 2,
    ]);

    expect($second->pending_guard)->toBe($revision->id)
        ->and($revision->approvalRequests()->count())->toBe(2);
});

test('phase 2c1 approval decisions are append-only and rejections must explain themselves', function () {
    $quote = QuoteFactory::createForDeal();
    $request = QuoteApprovalRequest::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
    ]);

    $approval = QuoteApprovalDecision::factory()->create([
        'quote_approval_request_id' => $request->id,
    ]);

    expect($approval->decision)->toBe(QuoteApprovalDecisionType::Approved)
        ->and($approval->reason)->toBeNull()
        ->and($request->fresh()->decisions)->toHaveCount(1);

    expect(fn () => $approval->update(['reason' => 'changed my mind']))
        ->toThrow(LogicException::class, 'append-only');

    expect(fn () => $approval->delete())
        ->toThrow(LogicException::class, 'append-only');

    expect(fn () => QuoteApprovalDecision::factory()->create([
        'quote_approval_request_id' => $request->id,
        'decision' => QuoteApprovalDecisionType::Rejected,
        'reason' => null,
    ]))->toThrow(LogicException::class, 'requires a reason');

    $rejection = QuoteApprovalDecision::factory()->rejected()->create([
        'quote_approval_request_id' => $request->id,
    ]);

    expect($rejection->reason)->toBe('Margin too low.');
});

test('phase 2c1 sent revisions accept no new tax calculations or approval requests', function () {
    $quote = QuoteFactory::createForDeal();
    $revision = $quote->currentRevision;

    QuoteRevision::$allowLifecycleMutation = true;
    $revision->forceFill([
        'status' => QuoteRevisionStatus::Sent,
        'sent_at' => now(),
        'lock_version' => $revision->lock_version + 1,
    ])->save();
    QuoteRevision::$allowLifecycleMutation = false;

    $sent = $revision->fresh();

    expect(fn () => QuoteRevisionTaxCalculation::factory()->create([
        'quote_revision_id' => $sent->id,
    ]))->toThrow(ImmutableQuoteRevisionException::class);

    expect(fn () => QuoteApprovalRequest::factory()->create([
        'quote_revision_id' => $sent->id,
    ]))->toThrow(ImmutableQuoteRevisionException::class);

    $contract = new QuoteApprovalInvalidationContract;

    expect(fn () => $contract->assertMayMutateFinancialContent($sent))
        ->toThrow(ImmutableQuoteRevisionException::class);

    expect(fn () => $contract->markPendingRequestsSuperseded($sent))
        ->toThrow(ImmutableQuoteRevisionException::class);

    $calculation = QuoteRevisionTaxGuard::allowingControlledWorkflow(
        fn () => QuoteRevisionTaxCalculation::factory()->create(['quote_revision_id' => $sent->id]),
    );

    expect($calculation->quote_revision_id)->toBe($sent->id)
        ->and(QuoteRevisionTaxGuard::inControlledWorkflow())->toBeFalse();
});

test('phase 2c1 rbac definitions add tax and approval permissions with the intended role split', function () {
    $keys = collect(RbacDefinitions::permissions())->pluck('key');
    $roles = RbacDefinitions::systemRoles();

    $newKeys = [
        'crm.quote.approve',
        'crm.quote.tax_calculate',
        'crm.quote.tax_override',
        'crm.tax_certificate.view',
        'crm.tax_certificate.manage',
    ];

    foreach ($newKeys as $key) {
        expect($keys)->toContain($key);
    }

    expect(array_intersect($newKeys, $roles['owner']['permissions']))->toBe($newKeys)
        ->and(array_intersect($newKeys, $roles['admin']['permissions']))->toBe($newKeys)
        ->and(array_values(array_intersect($newKeys, $roles['sales_manager']['permissions'])))->toBe([
            'crm.quote.approve',
            'crm.quote.tax_calculate',
            'crm.tax_certificate.view',
        ])
        ->and(array_values(array_intersect($newKeys, $roles['salesperson']['permissions'])))->toBe([
            'crm.quote.tax_calculate',
            'crm.tax_certificate.view',
        ])
        ->and(array_values(array_intersect($newKeys, $roles['finance']['permissions'])))->toBe([
            'crm.quote.tax_calculate',
            'crm.quote.tax_override',
            'crm.tax_certificate.view',
            'crm.tax_certificate.manage',
        ])
        ->and(array_intersect($newKeys, $roles['project_manager']['permissions']))->toBe([])
        ->and(array_intersect($newKeys, $roles['production_worker']['permissions']))->toBe([]);

    foreach (RbacDefinitions::parentRoleKeys() as $parentRoleKey) {
        expect(array_intersect($newKeys, $roles[$parentRoleKey]['permissions']))->toBe([]);
    }
});

test('phase 2c1 rollback removes only the new schema and remigrate restores it', function () {
    expect(Schema::hasTable('quote_revision_tax_calculations'))->toBeTrue()
        ->and(DB::table('migrations')->whereIn('migration', PHASE_2C1_MIGRATIONS)->count())->toBe(6);

    phase2c1Rollback();

    foreach (PHASE_2C1_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasColumn('quote_revisions', 'current_tax_calculation_id'))->toBeFalse()
        ->and(Schema::hasColumn('quote_revisions', 'current_approval_request_id'))->toBeFalse()
        ->and(Schema::hasTable('quotes'))->toBeTrue()
        ->and(Schema::hasTable('quote_revisions'))->toBeTrue()
        ->and(Schema::hasTable('quote_revision_line_items'))->toBeTrue()
        ->and(Schema::hasColumn('quote_revisions', 'tax_calculation_status'))->toBeTrue()
        ->and(DB::table('migrations')->whereIn('migration', PHASE_2C1_MIGRATIONS)->count())->toBe(0);

    Artisan::call('migrate', ['--force' => true]);

    foreach (PHASE_2C1_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::hasColumn('quote_revisions', 'current_tax_calculation_id'))->toBeTrue()
        ->and(Schema::hasColumn('quote_revisions', 'current_approval_request_id'))->toBeTrue();
});

test('phase 2c1 memberships referenced by tax and approval rows stay tenant scoped', function () {
    $quote = QuoteFactory::createForDeal();
    $foreignMembership = Membership::factory()->create();

    expect(fn () => QuoteApprovalRequest::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
        'requested_by_membership_id' => $foreignMembership->id,
        'requested_by_user_id' => $foreignMembership->user_id,
    ]))->toThrow(QueryException::class);

    expect(fn () => QuoteRevisionTaxCalculation::factory()->create([
        'quote_revision_id' => $quote->current_revision_id,
        'actor_membership_id' => $foreignMembership->id,
        'actor_user_id' => $foreignMembership->user_id,
    ]))->toThrow(QueryException::class);
});
