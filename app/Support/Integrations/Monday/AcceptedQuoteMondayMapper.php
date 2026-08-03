<?php

namespace App\Support\Integrations\Monday;

use App\Enums\IntegrationLineDetailMode;
use App\Enums\MondayColumnType;
use App\Enums\MondayIntakeLogicalKey;
use App\Models\Organization;
use App\Models\OrganizationIntegrationSetting;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionLineItem;
use App\Models\QuoteRevisionPartySnapshot;
use App\Models\QuoteRevisionTaxCalculation;
use App\Support\Catalog\ComponentCost\ComponentCostEstimator;
use App\Support\Integrations\Monday\Dto\MondayCreateItemRequest;
use InvalidArgumentException;

/**
 * Maps immutable accepted-quote snapshots to a Monday create-item request.
 */
final class AcceptedQuoteMondayMapper
{
    private const LINE_SUMMARY_MAX = 500;

    public function map(
        Quote $quote,
        QuoteRevision $revision,
        Organization $organization,
        OrganizationIntegrationSetting $settings,
        ?QuoteRevisionPartySnapshot $party,
        ?QuoteRevisionTaxCalculation $tax,
        string $deliveryIdempotencyKey,
    ): MondayCreateItemRequest {
        if ((int) $revision->quote_id !== (int) $quote->id) {
            throw new InvalidArgumentException('Revision does not belong to quote.');
        }

        if ((int) $settings->organization_id !== (int) $organization->id) {
            throw new InvalidArgumentException('Settings do not belong to organization.');
        }

        $mapping = MondayColumnMappingSet::fromArray($settings->column_mapping_json);
        $statusLabel = trim((string) (($settings->status_label_mappings_json ?? [])['intake_status'] ?? 'New Intake'));
        $integrationKey = $this->integrationKey($organization->id, $quote->id, (int) $revision->revision_number);

        $pretaxCents = (int) $revision->subtotal_cents - (int) $revision->discount_cents;
        $taxCents = (int) ($tax->tax_cents ?? $revision->tax_cents);
        $grandCents = (int) $revision->grand_total_cents;

        $halftoneUrl = route('org.quotes.show', [
            'organization' => $organization->slug,
            'quote' => $quote->id,
        ], absolute: true);

        $lineSummary = null;
        if ($settings->line_detail_mode === IntegrationLineDetailMode::Summary) {
            $lineSummary = $this->buildLineSummary($revision->lineItems);
        }

        $parts = AcceptedQuoteMondayMappingInput::fromApprovedParts([
            'quote_id' => $quote->id,
            'quote_revision_id' => $revision->id,
            'organization_id' => $organization->id,
            'parent_account_id' => $organization->parent_account_id,
            'integration_key' => $integrationKey,
            'quote_number' => (string) $quote->quote_number,
            'revision_number' => (int) $revision->revision_number,
            'company_name' => (string) ($party->customer_company_name ?? 'Customer'),
            'primary_contact' => $party?->contact_name,
            'salesperson' => $party?->salesperson_name,
            'accepted_date' => ($revision->accepted_at ?? now())->toDateString(),
            'pretax_total' => $this->centsToDecimalString($pretaxCents),
            'tax_total' => $this->centsToDecimalString($taxCents),
            'grand_total' => $this->centsToDecimalString($grandCents),
            'line_summary' => $lineSummary,
            'intake_status' => $statusLabel,
            'organization_integration_setting_id' => $settings->id,
            'board_id' => (string) $settings->board_id,
            'group_id' => $settings->group_id,
            'item_name_template' => $settings->item_name_template,
            'line_detail_mode' => $settings->line_detail_mode->value,
            'halftone_url' => $halftoneUrl,
        ]);

        $columnValues = $this->buildColumnValues($parts, $mapping, $organization->name);
        $itemName = $this->renderItemName($parts, $organization->name);

        return new MondayCreateItemRequest(
            boardId: (string) $settings->board_id,
            groupId: $settings->group_id,
            itemName: $itemName,
            integrationKey: $integrationKey,
            columnValues: $columnValues,
            apiVersion: $settings->api_version,
            idempotencyKey: $deliveryIdempotencyKey,
        );
    }

    public function integrationKey(int $organizationId, int $quoteId, int $revisionNumber): string
    {
        return "org:{$organizationId}:quote:{$quoteId}:rev:{$revisionNumber}";
    }

    public function centsToDecimalString(int $cents): string
    {
        $negative = $cents < 0;
        $abs = abs($cents);
        $dollars = intdiv($abs, 100);
        $remainder = $abs % 100;

        return ($negative ? '-' : '').$dollars.'.'.str_pad((string) $remainder, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  iterable<int, QuoteRevisionLineItem>  $lines
     */
    private function buildLineSummary(iterable $lines): string
    {
        $parts = [];

        foreach ($lines as $line) {
            $name = trim((string) $line->name_snapshot);
            if ($name === '') {
                continue;
            }
            $qty = rtrim(rtrim(ComponentCostEstimator::scaledToQuantity((int) $line->quantity_scaled), '0'), '.');
            $parts[] = $qty !== '' && $qty !== '0' ? "{$name} x {$qty}" : $name;
        }

        $summary = implode('; ', $parts);
        if (strlen($summary) > self::LINE_SUMMARY_MAX) {
            $summary = substr($summary, 0, self::LINE_SUMMARY_MAX - 1).'…';
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildColumnValues(
        AcceptedQuoteMondayMappingInput $parts,
        MondayColumnMappingSet $mapping,
        string $organizationName,
    ): array {
        $snapshot = $parts->customerSafeSnapshot();
        $values = [];

        $logicalValues = [
            MondayIntakeLogicalKey::IntegrationKey->value => $parts->integrationKey,
            MondayIntakeLogicalKey::Organization->value => $organizationName,
            MondayIntakeLogicalKey::QuoteNumber->value => $snapshot['quote_number'],
            MondayIntakeLogicalKey::RevisionNumber->value => (string) $snapshot['revision_number'],
            MondayIntakeLogicalKey::CompanyName->value => $snapshot['company_name'],
            MondayIntakeLogicalKey::PrimaryContact->value => $snapshot['primary_contact'],
            MondayIntakeLogicalKey::Salesperson->value => $snapshot['salesperson'],
            MondayIntakeLogicalKey::AcceptedDate->value => $snapshot['accepted_date'],
            MondayIntakeLogicalKey::PretaxTotal->value => $snapshot['pretax_total'],
            MondayIntakeLogicalKey::TaxTotal->value => $snapshot['tax_total'],
            MondayIntakeLogicalKey::GrandTotal->value => $snapshot['grand_total'],
            MondayIntakeLogicalKey::LineSummary->value => $snapshot['line_summary'],
            MondayIntakeLogicalKey::HalftoneUrl->value => $parts->halftoneUrl,
            MondayIntakeLogicalKey::IntakeStatus->value => $snapshot['intake_status'],
        ];

        foreach ($mapping->entries as $entry) {
            if (! $entry->enabled) {
                continue;
            }

            $logical = $entry->logicalKey->value;
            if (! array_key_exists($logical, $logicalValues)) {
                continue;
            }

            $raw = $logicalValues[$logical];
            if ($raw === null || $raw === '') {
                continue;
            }

            $values[$entry->columnId] = $this->formatColumnValue($entry->expectedType, (string) $raw, $logical);
        }

        return $values;
    }

    private function formatColumnValue(MondayColumnType $type, string $raw, string $logicalKey): mixed
    {
        return match ($type) {
            MondayColumnType::Text, MondayColumnType::LongText => $raw,
            MondayColumnType::Numbers => $raw,
            MondayColumnType::Date => ['date' => $raw],
            MondayColumnType::Status => ['label' => $raw],
            MondayColumnType::Link => [
                'url' => $raw,
                'text' => $logicalKey === MondayIntakeLogicalKey::HalftoneUrl->value ? 'Open in Halftone Brain' : $raw,
            ],
            default => $raw,
        };
    }

    private function renderItemName(AcceptedQuoteMondayMappingInput $parts, string $organizationName): string
    {
        $replacements = [
            '{quote_number}' => $parts->quoteNumber,
            '{company_name}' => $parts->companyName,
            '{organization}' => $organizationName,
            '{revision_number}' => (string) $parts->revisionNumber,
        ];

        $rendered = strtr($parts->itemNameTemplate, $replacements);
        $rendered = trim($rendered);

        if ($rendered === '') {
            throw new InvalidArgumentException('Rendered Monday item name must be non-blank.');
        }

        return substr($rendered, 0, MondayItemNameTemplate::MAX_LENGTH);
    }
}
