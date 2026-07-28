<?php

namespace App\Enums;

/**
 * Claimed basis for an exemption certificate.
 *
 * A category is only a claim. It never grants exemption on its own: the
 * certificate must also be verified, unexpired, and issued for the jurisdiction
 * being taxed. In particular, being a nonprofit or a school does not by itself
 * make a sale exempt.
 */
enum TaxExemptionCategory: string
{
    case Resale = 'resale';
    case Government = 'government';
    case School = 'school';
    case Hospital = 'hospital';
    case QualifyingNonprofit = 'qualifying_nonprofit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resale => 'Resale',
            self::Government => 'Government',
            self::School => 'School',
            self::Hospital => 'Hospital',
            self::QualifyingNonprofit => 'Qualifying nonprofit',
            self::Other => 'Other',
        };
    }
}
