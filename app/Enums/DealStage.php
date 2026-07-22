<?php

namespace App\Enums;

enum DealStage: string
{
    case Lead = 'lead';
    case Qualified = 'qualified';
    case Quoting = 'quoting';
    case QuoteSent = 'quote_sent';
    case Negotiations = 'negotiations';
    case QuoteWon = 'quote_won';
    case QuoteLost = 'quote_lost';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Lead',
            self::Qualified => 'Qualified',
            self::Quoting => 'Quoting',
            self::QuoteSent => 'Quote sent',
            self::Negotiations => 'Negotiations',
            self::QuoteWon => 'Quote won',
            self::QuoteLost => 'Quote lost',
        };
    }

    /**
     * @return list<self>
     */
    public static function pipelineOrder(): array
    {
        return [
            self::Lead,
            self::Qualified,
            self::Quoting,
            self::QuoteSent,
            self::Negotiations,
            self::QuoteWon,
            self::QuoteLost,
        ];
    }
}
