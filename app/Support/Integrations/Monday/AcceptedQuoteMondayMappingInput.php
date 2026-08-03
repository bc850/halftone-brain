<?php

namespace App\Support\Integrations\Monday;

use InvalidArgumentException;

/**
 * Accepted-quote → Monday mapping input contract.
 *
 * Distinguishes immutable identifiers, customer-safe snapshot fields, integration
 * configuration references, and the internal authenticated Halftone URL.
 * Explicitly excludes internal commercial and credential fields.
 */
final readonly class AcceptedQuoteMondayMappingInput
{
    /**
     * @param  array{
     *     quote_id: int,
     *     quote_revision_id: int,
     *     organization_id: int,
     *     parent_account_id: int,
     *     integration_key: string,
     *     quote_number: string,
     *     revision_number: int,
     *     company_name: string,
     *     primary_contact?: string|null,
     *     salesperson?: string|null,
     *     accepted_date: string,
     *     pretax_total: string,
     *     tax_total: string,
     *     grand_total: string,
     *     line_summary?: string|null,
     *     intake_status: string,
     *     organization_integration_setting_id: int,
     *     board_id: string,
     *     group_id?: string|null,
     *     item_name_template: string,
     *     line_detail_mode: string,
     *     halftone_url: string,
     * }  $parts
     */
    public static function fromApprovedParts(array $parts): self
    {
        MondaySensitivePayloadGuard::assertNoSensitiveKeys($parts);

        $required = [
            'quote_id',
            'quote_revision_id',
            'organization_id',
            'parent_account_id',
            'integration_key',
            'quote_number',
            'revision_number',
            'company_name',
            'accepted_date',
            'pretax_total',
            'tax_total',
            'grand_total',
            'intake_status',
            'organization_integration_setting_id',
            'board_id',
            'item_name_template',
            'line_detail_mode',
            'halftone_url',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $parts)) {
                throw new InvalidArgumentException("Accepted quote Monday mapping input missing [{$key}].");
            }
        }

        return new self(
            quoteId: (int) $parts['quote_id'],
            quoteRevisionId: (int) $parts['quote_revision_id'],
            organizationId: (int) $parts['organization_id'],
            parentAccountId: (int) $parts['parent_account_id'],
            integrationKey: (string) $parts['integration_key'],
            quoteNumber: (string) $parts['quote_number'],
            revisionNumber: (int) $parts['revision_number'],
            companyName: (string) $parts['company_name'],
            primaryContact: isset($parts['primary_contact']) ? (string) $parts['primary_contact'] : null,
            salesperson: isset($parts['salesperson']) ? (string) $parts['salesperson'] : null,
            acceptedDate: (string) $parts['accepted_date'],
            pretaxTotal: (string) $parts['pretax_total'],
            taxTotal: (string) $parts['tax_total'],
            grandTotal: (string) $parts['grand_total'],
            lineSummary: isset($parts['line_summary']) ? (string) $parts['line_summary'] : null,
            intakeStatus: (string) $parts['intake_status'],
            organizationIntegrationSettingId: (int) $parts['organization_integration_setting_id'],
            boardId: (string) $parts['board_id'],
            groupId: isset($parts['group_id']) ? (string) $parts['group_id'] : null,
            itemNameTemplate: MondayItemNameTemplate::assertValid((string) $parts['item_name_template']),
            lineDetailMode: (string) $parts['line_detail_mode'],
            halftoneUrl: (string) $parts['halftone_url'],
        );
    }

    private function __construct(
        public int $quoteId,
        public int $quoteRevisionId,
        public int $organizationId,
        public int $parentAccountId,
        public string $integrationKey,
        public string $quoteNumber,
        public int $revisionNumber,
        public string $companyName,
        public ?string $primaryContact,
        public ?string $salesperson,
        public string $acceptedDate,
        public string $pretaxTotal,
        public string $taxTotal,
        public string $grandTotal,
        public ?string $lineSummary,
        public string $intakeStatus,
        public int $organizationIntegrationSettingId,
        public string $boardId,
        public ?string $groupId,
        public string $itemNameTemplate,
        public string $lineDetailMode,
        public string $halftoneUrl,
    ) {}

    /**
     * Immutable accepted-quote identifiers.
     *
     * @return array{quote_id: int, quote_revision_id: int, organization_id: int, parent_account_id: int, integration_key: string}
     */
    public function identifiers(): array
    {
        return [
            'quote_id' => $this->quoteId,
            'quote_revision_id' => $this->quoteRevisionId,
            'organization_id' => $this->organizationId,
            'parent_account_id' => $this->parentAccountId,
            'integration_key' => $this->integrationKey,
        ];
    }

    /**
     * Customer-safe snapshot fields only.
     *
     * @return array{
     *     quote_number: string,
     *     revision_number: int,
     *     company_name: string,
     *     primary_contact: string|null,
     *     salesperson: string|null,
     *     accepted_date: string,
     *     pretax_total: string,
     *     tax_total: string,
     *     grand_total: string,
     *     line_summary: string|null,
     *     intake_status: string
     * }
     */
    public function customerSafeSnapshot(): array
    {
        return [
            'quote_number' => $this->quoteNumber,
            'revision_number' => $this->revisionNumber,
            'company_name' => $this->companyName,
            'primary_contact' => $this->primaryContact,
            'salesperson' => $this->salesperson,
            'accepted_date' => $this->acceptedDate,
            'pretax_total' => $this->pretaxTotal,
            'tax_total' => $this->taxTotal,
            'grand_total' => $this->grandTotal,
            'line_summary' => $this->lineSummary,
            'intake_status' => $this->intakeStatus,
        ];
    }

    /**
     * Integration configuration references (no secrets).
     *
     * @return array{
     *     organization_integration_setting_id: int,
     *     board_id: string,
     *     group_id: string|null,
     *     item_name_template: string,
     *     line_detail_mode: string
     * }
     */
    public function integrationConfiguration(): array
    {
        return [
            'organization_integration_setting_id' => $this->organizationIntegrationSettingId,
            'board_id' => $this->boardId,
            'group_id' => $this->groupId,
            'item_name_template' => $this->itemNameTemplate,
            'line_detail_mode' => $this->lineDetailMode,
        ];
    }
}
