<?php

namespace Database\Factories;

use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteDocumentType;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Support\Quotes\Documents\CustomerQuoteDocumentIntegrity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuoteRevisionDocument>
 */
class QuoteRevisionDocumentFactory extends Factory
{
    protected $model = QuoteRevisionDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = [
            'document_type' => 'customer_quote',
            'totals' => [
                'gross_line_subtotal_cents' => 1000,
                'line_discount_total_cents' => 0,
                'net_line_subtotal_cents' => 1000,
                'quote_discount_total_cents' => 0,
                'positive_adjustment_total_cents' => 0,
                'final_pretax_amount_cents' => 1000,
                'tax_status' => 'pending',
                'tax_unresolved' => true,
                'customer_grand_total_final' => false,
                'lines' => [],
            ],
            'terms_checksum' => (new CustomerQuoteDocumentIntegrity)->termsChecksum('Standard terms.'),
        ];

        return [
            'quote_revision_id' => QuoteRevision::factory(),
            'quote_id' => fn (array $attributes): int => (int) QuoteRevision::query()
                ->whereKey($attributes['quote_revision_id'])
                ->value('quote_id'),
            'parent_account_id' => fn (array $attributes): int => (int) Quote::query()
                ->whereKey($attributes['quote_id'])
                ->value('parent_account_id'),
            'organization_id' => fn (array $attributes): int => (int) Quote::query()
                ->whereKey($attributes['quote_id'])
                ->value('organization_id'),
            'document_type' => QuoteDocumentType::CustomerQuote,
            'document_version' => 1,
            'generation_status' => QuoteDocumentGenerationStatus::Generated,
            'customer_payload_snapshot_json' => $payload,
            'private_html_path' => 'private/quotes/documents/example.html',
            'private_pdf_path' => 'private/quotes/documents/example.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 1024,
            'content_sha256' => (new CustomerQuoteDocumentIntegrity)->payloadChecksum($payload),
            'generated_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'generation_status' => QuoteDocumentGenerationStatus::Pending,
            'customer_payload_snapshot_json' => null,
            'private_html_path' => null,
            'private_pdf_path' => null,
            'mime_type' => null,
            'byte_size' => null,
            'content_sha256' => null,
            'generated_at' => null,
        ]);
    }

    public function failed(string $code = 'generation_failed'): static
    {
        return $this->state(fn (): array => [
            'generation_status' => QuoteDocumentGenerationStatus::Failed,
            'failure_code' => $code,
            'failure_message' => 'Document generation failed.',
            'generated_at' => null,
            'content_sha256' => null,
        ]);
    }
}
