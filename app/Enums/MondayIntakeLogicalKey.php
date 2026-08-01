<?php

namespace App\Enums;

/**
 * Approved logical mapping keys for Monday accepted-quote intake (v1).
 *
 * QuoteRevision.expiration_date is quote expiration and must never appear here
 * as a production due date. A future requested-delivery field is separate.
 */
enum MondayIntakeLogicalKey: string
{
    case IntegrationKey = 'integration_key';
    case Organization = 'organization';
    case QuoteNumber = 'quote_number';
    case RevisionNumber = 'revision_number';
    case CompanyName = 'company_name';
    case PrimaryContact = 'primary_contact';
    case Salesperson = 'salesperson';
    case AcceptedDate = 'accepted_date';
    case PretaxTotal = 'pretax_total';
    case TaxTotal = 'tax_total';
    case GrandTotal = 'grand_total';
    case LineSummary = 'line_summary';
    case HalftoneUrl = 'halftone_url';
    case IntakeStatus = 'intake_status';

    /**
     * Keys that must be present, enabled, and correctly typed before activation.
     *
     * @return list<self>
     */
    public static function requiredForActivation(): array
    {
        return [
            self::IntegrationKey,
            self::QuoteNumber,
            self::CompanyName,
            self::AcceptedDate,
            self::GrandTotal,
            self::HalftoneUrl,
            self::IntakeStatus,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
