<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\QuoteRevisionStatus;
use App\Enums\TaxCertificateVerificationStatus;
use App\Http\Controllers\OrganizationTaxSettingsController;
use App\Http\Controllers\QuoteTaxController;
use App\Http\Resources\ApprovalRequestResource;
use App\Http\Resources\TaxCalculationResource;
use App\Http\Resources\TaxCertificateResource;
use App\Http\Resources\TaxProfileResource;
use App\Http\Resources\TaxRateResource;
use App\Models\OrganizationCompanyTaxCertificate;
use App\Models\OrganizationTaxProfile;
use App\Models\OrganizationTaxRate;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\User;
use App\Support\Quotes\Approval\QuoteApprovalEvaluator;
use Illuminate\Support\Carbon;

/**
 * The tax and approval panels shared by the draft builder and the read-only
 * revision view.
 *
 * Both pages show the same facts; only the actions differ, and which of those are
 * offered is decided here rather than in the templates so the buttons can never
 * disagree with what the domain services would actually accept.
 */
trait BuildsQuoteTaxAndApprovalPanels
{
    /**
     * @return array<string, mixed>
     */
    protected function taxPanel(Quote $quote, QuoteRevision $revision, User $user): array
    {
        $canCalculate = $user->can('calculateTax', $quote);
        $revision->loadMissing(['currentTaxCalculation', 'partySnapshot']);
        $snapshot = $revision->partySnapshot;

        return [
            'status' => $revision->tax_calculation_status->value,
            'is_resolved' => $revision->tax_calculation_status->isResolved(),
            'review_reasons' => $this->reviewReasons($revision),
            'current' => TaxCalculationResource::make($revision->currentTaxCalculation),
            // History is the audit trail behind the current figure; it is only worth
            // showing to someone who could change that figure.
            'history' => $canCalculate
                ? TaxCalculationResource::collection(QuoteTaxController::historyFor($revision))
                : [],
            'profile' => TaxProfileResource::make($this->taxProfileFor($quote)),
            'rates' => TaxRateResource::collection($this->selectableRatesFor($quote)),
            'certificates' => TaxCertificateResource::collection(
                $this->selectableCertificatesFor($quote),
                false,
            ),
            'service_address' => $snapshot?->service_address_json,
            'billing_address' => $snapshot?->billing_address_json,
            'can_calculate' => $canCalculate && $revision->status === QuoteRevisionStatus::Draft,
            'can_override' => $user->can('overrideTax', $quote)
                && $revision->status === QuoteRevisionStatus::Draft,
            'disclaimer' => OrganizationTaxSettingsController::RATE_DISCLAIMER,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function approvalPanel(Quote $quote, QuoteRevision $revision, User $user): array
    {
        $revision->loadMissing('currentApprovalRequest');

        $isDraft = $revision->status === QuoteRevisionStatus::Draft;
        $isPending = $revision->status === QuoteRevisionStatus::PendingApproval;
        $canUpdate = $user->can('update', $quote);
        $taxResolved = $revision->tax_calculation_status->isResolved();

        return [
            'status' => $revision->status->value,
            'approval_required' => $revision->approval_required,
            'reasons' => $this->snapshotReasons($revision),
            'explanations' => $this->snapshotExplanations($revision),
            'current_request' => ApprovalRequestResource::make($revision->currentApprovalRequest),
            'reason_catalog' => QuoteApprovalEvaluator::reasonCatalog(),
            'can_evaluate' => $canUpdate && $isDraft,
            // An unresolved tax position blocks submission, so the button is not
            // offered rather than offered and refused.
            'can_submit' => $canUpdate && $isDraft && $taxResolved,
            'can_withdraw' => $canUpdate && $isPending,
            'can_return_to_draft' => $canUpdate && $revision->status === QuoteRevisionStatus::Approved,
            'can_decide' => $isPending && $user->can('approve', $quote),
            'blocked_by_tax' => $isDraft && ! $taxResolved,
        ];
    }

    /**
     * Why the last run could not resolve a position. Recorded on the revision
     * snapshot rather than the calculation row, since it describes the revision's
     * current state rather than one historical run.
     *
     * @return list<string>
     */
    private function reviewReasons(QuoteRevision $revision): array
    {
        $reasons = $revision->tax_snapshot_json['review_reasons'] ?? null;

        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason)));
    }

    private function taxProfileFor(Quote $quote): ?OrganizationTaxProfile
    {
        return OrganizationTaxProfile::query()
            ->where('organization_id', $quote->organization_id)
            ->first();
    }

    /**
     * Rates that could legitimately be applied today: active, and covering now.
     *
     * @return list<OrganizationTaxRate>
     */
    private function selectableRatesFor(Quote $quote): array
    {
        $today = Carbon::now()->toDateString();

        return array_values(OrganizationTaxRate::query()
            ->where('organization_id', $quote->organization_id)
            ->where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(function ($builder) use ($today): void {
                $builder->whereNull('effective_through')
                    ->orWhere('effective_through', '>=', $today);
            })
            ->orderBy('jurisdiction_code')
            ->get()
            ->all());
    }

    /**
     * Only verified certificates can produce an exemption, so only those are
     * offered. Whether one actually applies to this jurisdiction is still decided
     * by the calculator.
     *
     * @return list<OrganizationCompanyTaxCertificate>
     */
    private function selectableCertificatesFor(Quote $quote): array
    {
        return array_values(OrganizationCompanyTaxCertificate::query()
            ->where('organization_id', $quote->organization_id)
            ->where('organization_company_id', $quote->organization_company_id)
            ->where('verification_status', TaxCertificateVerificationStatus::Verified)
            ->orderByDesc('effective_date')
            ->get()
            ->all());
    }

    /**
     * @return list<string>
     */
    private function snapshotReasons(QuoteRevision $revision): array
    {
        $reasons = $revision->approval_reason_snapshot['reasons'] ?? null;

        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason)));
    }

    /**
     * @return array<string, string>
     */
    private function snapshotExplanations(QuoteRevision $revision): array
    {
        $explanations = $revision->approval_reason_snapshot['explanations'] ?? null;

        if (! is_array($explanations)) {
            return [];
        }

        $safe = [];

        foreach ($explanations as $reason => $explanation) {
            if (is_string($reason) && is_string($explanation)) {
                $safe[$reason] = $explanation;
            }
        }

        return $safe;
    }
}
