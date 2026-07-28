<?php

namespace App\Support\Quotes\Documents;

use App\Enums\QuoteApprovalRequestStatus;
use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteDocumentType;
use App\Enums\QuoteRevisionStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionAdjustment;
use App\Models\QuoteRevisionDocument;
use App\Models\QuoteRevisionLineItem;
use App\Models\QuoteRevisionPartySnapshot;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Quotes\Totals\QuoteAdjustmentCalculationInput;
use App\Support\Quotes\Totals\QuoteLineCalculationInput;
use App\Support\Quotes\Totals\QuoteTotalsCalculator;
use App\Support\Quotes\Totals\QuoteTotalsResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates immutable customer-safe quote documents (HTML + PDF).
 *
 * Pending rows are created first; rendering happens after that transaction so a
 * failed render can finalize as Failed without leaving partial public artifacts.
 */
final class QuoteDocumentGenerationService
{
    public function __construct(
        private QuoteTotalsCalculator $calculator,
        private CustomerQuoteDocumentIntegrity $integrity,
        private QuotePdfRenderer $renderer,
        private Auditor $auditor,
    ) {}

    public function generate(
        Quote $quote,
        QuoteRevision $revision,
        ?User $actor = null,
        ?Membership $actorMembership = null,
    ): QuoteRevisionDocument {
        $prepared = DB::transaction(function () use ($quote, $revision, $actor, $actorMembership): array {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lockPair($quote, $revision);
            $this->assertPrerequisites($lockedQuote, $lockedRevision);

            $payload = $this->buildPayload($lockedQuote, $lockedRevision);
            $nextVersion = ((int) QuoteRevisionDocument::query()
                ->where('quote_revision_id', $lockedRevision->id)
                ->where('document_type', QuoteDocumentType::CustomerQuote->value)
                ->max('document_version')) + 1;

            $document = QuoteRevisionDocument::query()->create([
                'parent_account_id' => $lockedQuote->parent_account_id,
                'organization_id' => $lockedQuote->organization_id,
                'quote_id' => $lockedQuote->id,
                'quote_revision_id' => $lockedRevision->id,
                'document_type' => QuoteDocumentType::CustomerQuote,
                'document_version' => $nextVersion,
                'generation_status' => QuoteDocumentGenerationStatus::Pending,
                'generated_by_membership_id' => $actorMembership?->id,
                'generated_by_user_id' => $actor?->id,
                'correlation_id' => (string) Str::uuid(),
            ]);

            return [
                'document' => $document,
                'payload' => $payload,
                'quote' => $lockedQuote,
                'revision' => $lockedRevision,
            ];
        });

        /** @var QuoteRevisionDocument $document */
        $document = $prepared['document'];
        /** @var array<string, mixed> $payload */
        $payload = $prepared['payload'];
        /** @var Quote $lockedQuote */
        $lockedQuote = $prepared['quote'];
        /** @var QuoteRevision $lockedRevision */
        $lockedRevision = $prepared['revision'];

        $htmlPath = $this->relativePath($lockedQuote, $lockedRevision, $document, 'customer.html');
        $pdfPath = $this->relativePath($lockedQuote, $lockedRevision, $document, 'customer.pdf');

        try {
            $rendered = $this->renderer->render($payload);
            Storage::disk('local')->put($htmlPath, $rendered->html);
            Storage::disk('local')->put($pdfPath, $rendered->pdf);

            return $this->finalizeGenerated(
                document: $document,
                quote: $lockedQuote,
                revision: $lockedRevision,
                payload: $payload,
                htmlPath: $htmlPath,
                pdfPath: $pdfPath,
                pdfBytes: $rendered->pdf,
                actor: $actor,
            );
        } catch (\Throwable $exception) {
            $this->cleanupArtifacts([$htmlPath, $pdfPath]);

            $this->finalizeFailed(
                document: $document,
                quote: $lockedQuote,
                revision: $lockedRevision,
                exception: $exception,
                actor: $actor,
            );

            throw new InvalidQuoteDocumentException(
                'Customer document generation failed: '.$exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * @return array{quote: Quote, revision: QuoteRevision}
     */
    private function lockPair(Quote $quote, QuoteRevision $revision): array
    {
        /** @var Quote $lockedQuote */
        $lockedQuote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

        $lockedRevision = QuoteRevision::query()
            ->whereKey($revision->id)
            ->where('quote_id', $lockedQuote->id)
            ->lockForUpdate()
            ->first();

        if ($lockedRevision === null) {
            throw new InvalidQuoteDocumentException('Revision does not belong to the given quote.');
        }

        return ['quote' => $lockedQuote, 'revision' => $lockedRevision];
    }

    private function assertPrerequisites(Quote $quote, QuoteRevision $revision): void
    {
        if ($quote->current_revision_id !== $revision->id) {
            throw new InvalidQuoteDocumentException('Only the current quote revision can generate a customer document.');
        }

        if ($revision->status !== QuoteRevisionStatus::Approved) {
            throw new InvalidQuoteDocumentException('Customer documents can only be generated for approved revisions.');
        }

        if (! $revision->tax_calculation_status->isResolved()) {
            throw new InvalidQuoteDocumentException('Tax must be calculated or exempt before generating a customer document.');
        }

        if ($revision->partySnapshot === null && ! QuoteRevisionPartySnapshot::query()->where('quote_revision_id', $revision->id)->exists()) {
            throw new InvalidQuoteDocumentException('A party snapshot is required before generating a customer document.');
        }

        $hasLines = QuoteRevisionLineItem::query()->where('quote_revision_id', $revision->id)->exists();
        $hasAdjustments = QuoteRevisionAdjustment::query()->where('quote_revision_id', $revision->id)->exists();
        if (! $hasLines && ! $hasAdjustments) {
            throw new InvalidQuoteDocumentException('Customer-visible lines or adjustments are required.');
        }

        if (trim((string) $revision->terms_text) === '') {
            throw new InvalidQuoteDocumentException('Terms text is required before generating a customer document.');
        }

        if ($revision->expiration_date === null || $revision->expiration_date->startOfDay()->lte(now()->startOfDay())) {
            throw new InvalidQuoteDocumentException('A future expiration date is required before generating a customer document.');
        }

        $openApproval = QuoteApprovalRequest::query()
            ->where('quote_revision_id', $revision->id)
            ->where('status', QuoteApprovalRequestStatus::Pending->value)
            ->exists();

        if ($openApproval) {
            throw new InvalidQuoteDocumentException('An unresolved approval request blocks document generation.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Quote $quote, QuoteRevision $revision): array
    {
        $revision->loadMissing('partySnapshot');
        $party = $revision->partySnapshot;
        if (! $party instanceof QuoteRevisionPartySnapshot) {
            throw new InvalidQuoteDocumentException('A party snapshot is required before generating a customer document.');
        }

        $lines = QuoteRevisionLineItem::query()
            ->where('quote_revision_id', $revision->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $adjustments = QuoteRevisionAdjustment::query()
            ->where('quote_revision_id', $revision->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $lineInputs = [];
        foreach ($lines as $line) {
            $lineInputs[] = $this->toLineInput($line);
        }

        $adjustmentInputs = [];
        foreach ($adjustments as $adjustment) {
            $adjustmentInputs[] = $this->toAdjustmentInput($adjustment);
        }

        $totals = $this->calculator->calculate($lineInputs, $adjustmentInputs);

        return $this->integrity->buildTaxResolvedCustomerDocumentPayload(
            totals: $totals,
            lineInputs: $lineInputs,
            adjustments: $this->projectAdjustments($adjustments, $totals),
            party: $this->projectParty($party),
            header: [
                'quote_number' => $quote->quote_number,
                'revision_number' => $revision->revision_number,
                'issue_date' => $revision->issue_date?->toDateString(),
                'expiration_date' => $revision->expiration_date?->toDateString(),
                'introduction' => $revision->introduction,
                'customer_notes' => $revision->customer_notes,
                'terms_text' => (string) $revision->terms_text,
                'requested_deposit_cents' => $revision->requested_deposit_cents,
            ],
            taxCents: (int) $revision->tax_cents,
            taxStatus: $revision->tax_calculation_status->value,
        );
    }

    /**
     * @param  Collection<int, QuoteRevisionAdjustment>  $adjustments
     * @return list<array{key: string, description: string, adjustment_type: string, amount_cents: int}>
     */
    private function projectAdjustments(Collection $adjustments, QuoteTotalsResult $totals): array
    {
        $projected = [];
        foreach ($adjustments as $adjustment) {
            $amountCents = (int) $adjustment->amount_cents;
            foreach ($totals->adjustments as $result) {
                if ($result->key === (string) $adjustment->id) {
                    $amountCents = $result->amountCents;
                    break;
                }
            }

            $projected[] = [
                'key' => (string) $adjustment->id,
                'description' => (string) $adjustment->description_snapshot,
                'adjustment_type' => $adjustment->adjustment_type->value,
                'amount_cents' => $amountCents,
            ];
        }

        return $projected;
    }

    /**
     * @return array<string, mixed>
     */
    private function projectParty(QuoteRevisionPartySnapshot $party): array
    {
        return [
            'selling_organization_name' => $party->selling_organization_name,
            'customer_company_name' => $party->customer_company_name,
            'customer_number' => $party->customer_number,
            'contact_name' => $party->contact_name,
            'contact_email' => $party->contact_email,
            'contact_phone' => $party->contact_phone,
            'billing_address_display' => $this->formatAddress($party->billing_address_json),
            'service_address_display' => $this->formatAddress($party->service_address_json),
            'salesperson_name' => $party->salesperson_name,
            'salesperson_email' => $party->salesperson_email,
            'preparer_name' => $party->preparer_name,
            'preparer_email' => $party->preparer_email,
            'customer_po_reference' => $party->customer_po_reference,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private function formatAddress(?array $address): ?string
    {
        if ($address === null || $address === []) {
            return null;
        }

        $parts = array_filter([
            $address['line1'] ?? $address['address_line1'] ?? null,
            $address['line2'] ?? $address['address_line2'] ?? null,
            trim(implode(', ', array_filter([
                $address['city'] ?? null,
                $address['state'] ?? $address['region'] ?? null,
                $address['postal_code'] ?? $address['zip'] ?? null,
            ], static fn (mixed $part): bool => is_string($part) && $part !== ''))),
            $address['country'] ?? null,
        ], static fn (mixed $part): bool => is_string($part) && $part !== '');

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finalizeGenerated(
        QuoteRevisionDocument $document,
        Quote $quote,
        QuoteRevision $revision,
        array $payload,
        string $htmlPath,
        string $pdfPath,
        string $pdfBytes,
        ?User $actor,
    ): QuoteRevisionDocument {
        return DB::transaction(function () use (
            $document,
            $quote,
            $revision,
            $payload,
            $htmlPath,
            $pdfPath,
            $pdfBytes,
            $actor,
        ): QuoteRevisionDocument {
            /** @var QuoteRevisionDocument $locked */
            $locked = QuoteRevisionDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            /** @var QuoteRevision $lockedRevision */
            $lockedRevision = QuoteRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();

            QuoteRevisionDocument::$allowGenerationFinalization = true;

            try {
                $locked->forceFill([
                    'generation_status' => QuoteDocumentGenerationStatus::Generated,
                    'customer_payload_snapshot_json' => $payload,
                    'private_html_path' => $htmlPath,
                    'private_pdf_path' => $pdfPath,
                    'mime_type' => 'application/pdf',
                    'byte_size' => strlen($pdfBytes),
                    'content_sha256' => $this->integrity->fileChecksum($pdfBytes),
                    'generated_at' => now(),
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();
            } finally {
                QuoteRevisionDocument::$allowGenerationFinalization = false;
            }

            $lockedRevision->forceFill([
                'current_document_id' => $locked->id,
            ])->save();

            $this->auditor->append(
                parentAccount: ParentAccount::query()->findOrFail($quote->parent_account_id),
                action: 'crm.quote.document_generated',
                subjectType: QuoteRevisionDocument::class,
                subjectId: $locked->id,
                organization: Organization::query()->findOrFail($quote->organization_id),
                actor: $actor,
                after: [
                    'quote_revision_id' => $revision->id,
                    'document_version' => $locked->document_version,
                    'content_sha256' => $locked->content_sha256,
                ],
                correlationId: $locked->correlation_id,
            );

            return $locked->fresh() ?? $locked;
        });
    }

    private function finalizeFailed(
        QuoteRevisionDocument $document,
        Quote $quote,
        QuoteRevision $revision,
        \Throwable $exception,
        ?User $actor,
    ): QuoteRevisionDocument {
        return DB::transaction(function () use ($document, $quote, $revision, $exception, $actor): QuoteRevisionDocument {
            /** @var QuoteRevisionDocument $locked */
            $locked = QuoteRevisionDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            QuoteRevisionDocument::$allowGenerationFinalization = true;

            try {
                $locked->forceFill([
                    'generation_status' => QuoteDocumentGenerationStatus::Failed,
                    'failure_code' => 'generation_failed',
                    'failure_message' => Str::limit($exception->getMessage(), 500, ''),
                    'generated_at' => null,
                    'private_html_path' => null,
                    'private_pdf_path' => null,
                    'mime_type' => null,
                    'byte_size' => null,
                    'content_sha256' => null,
                    'customer_payload_snapshot_json' => null,
                ])->save();
            } finally {
                QuoteRevisionDocument::$allowGenerationFinalization = false;
            }

            $this->auditor->append(
                parentAccount: ParentAccount::query()->findOrFail($quote->parent_account_id),
                action: 'crm.quote.document_generation_failed',
                subjectType: QuoteRevisionDocument::class,
                subjectId: $locked->id,
                organization: Organization::query()->findOrFail($quote->organization_id),
                actor: $actor,
                after: [
                    'quote_revision_id' => $revision->id,
                    'document_version' => $locked->document_version,
                    'failure_code' => $locked->failure_code,
                ],
                correlationId: $locked->correlation_id,
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  list<string>  $paths
     */
    private function cleanupArtifacts(array $paths): void
    {
        foreach ($paths as $path) {
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function relativePath(
        Quote $quote,
        QuoteRevision $revision,
        QuoteRevisionDocument $document,
        string $filename,
    ): string {
        return implode('/', [
            'quotes',
            (string) $quote->organization_id,
            (string) $quote->id,
            'revisions',
            (string) $revision->id,
            'documents',
            'v'.$document->document_version,
            $filename,
        ]);
    }

    private function toLineInput(QuoteRevisionLineItem $line): QuoteLineCalculationInput
    {
        return new QuoteLineCalculationInput(
            key: (string) $line->id,
            lineType: $line->line_type,
            nameSnapshot: $line->name_snapshot,
            customerDescriptionSnapshot: $line->customer_description_snapshot,
            internalDescriptionSnapshot: $line->internal_description_snapshot,
            productId: $line->product_id,
            organizationProductId: $line->organization_product_id,
            skuSnapshot: $line->sku_snapshot,
            itemKindSnapshot: $line->item_kind_snapshot,
            quantityScaled: $line->quantity_scaled,
            uomSnapshot: $line->uom_snapshot,
            calculatedUnitPriceCents: $line->calculated_unit_price_cents,
            finalUnitPriceCents: $line->final_unit_price_cents,
            lineDiscountMethod: $line->line_discount_method,
            lineDiscountValue: $line->line_discount_value,
            isTaxable: $line->is_taxable,
            priceOverride: $line->price_override,
            overrideReason: $line->override_reason,
            belowMinimum: $line->below_minimum,
            approvalRequired: $line->approval_required,
            approvalReasons: $this->reasonList($line->approval_reason_json),
            materialCostMicroUnits: $line->material_cost_micro_units,
            laborCostMicroUnits: $line->labor_cost_micro_units,
            overheadCostMicroUnits: $line->overhead_cost_micro_units,
            totalCostMicroUnits: $line->total_cost_micro_units,
            pricingMethodSnapshot: $line->pricing_method_snapshot,
            markupBasisPointsSnapshot: $line->markup_basis_points_snapshot,
            marginBasisPointsSnapshot: $line->margin_basis_points_snapshot,
            pricingVersionSnapshot: $line->pricing_version_snapshot,
            componentsVersionSnapshot: $line->components_version_snapshot,
            componentCostBreakdown: $line->component_cost_breakdown_json,
            pricingInputSnapshot: $line->pricing_input_snapshot_json,
            pricingResultSnapshot: $line->pricing_result_snapshot_json,
        );
    }

    private function toAdjustmentInput(QuoteRevisionAdjustment $adjustment): QuoteAdjustmentCalculationInput
    {
        return new QuoteAdjustmentCalculationInput(
            key: (string) $adjustment->id,
            adjustmentType: $adjustment->adjustment_type,
            descriptionSnapshot: $adjustment->description_snapshot,
            method: $adjustment->method,
            inputValue: $adjustment->input_value,
            isTaxable: $adjustment->is_taxable,
            approvalRequired: $adjustment->approval_required,
            approvalReasons: $this->reasonList($adjustment->approval_reason_json),
        );
    }

    /**
     * @param  array<string, mixed>|null  $approvalReasonJson
     * @return list<string>|null
     */
    private function reasonList(?array $approvalReasonJson): ?array
    {
        $reasons = $approvalReasonJson['reasons'] ?? null;

        if (! is_array($reasons)) {
            return null;
        }

        return array_values(array_filter($reasons, static fn (mixed $reason): bool => is_string($reason)));
    }
}
