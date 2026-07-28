<?php

namespace App\Support\Quotes\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Options;

/**
 * Renders customer-safe quote HTML and PDF using locked-down Dompdf options.
 *
 * Callers must supply a customer-safe view model only — never raw user HTML.
 */
class QuotePdfRenderer
{
    public function __construct(
        private QuoteDompdfOptions $options,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function render(array $document): QuotePdfRenderResult
    {
        (new CustomerQuoteDocumentIntegrity)->assertNoForbiddenKeys($document);

        $html = view('quotes.pdf.customer', [
            'document' => $document,
            'fontFamily' => QuoteDompdfOptions::DEFAULT_FONT,
        ])->render();

        $pdf = Pdf::loadHTML($html);
        $pdf->setOptions($this->options->secureOptions());
        $pdf->setPaper('letter', 'portrait');

        $options = $pdf->getDomPDF()->getOptions();
        $this->assertSecureOptions($options);

        return new QuotePdfRenderResult(
            html: $html,
            pdf: $pdf->output(),
        );
    }

    private function assertSecureOptions(Options $options): void
    {
        if ($options->getIsRemoteEnabled() || $options->getIsPhpEnabled() || $options->getIsJavascriptEnabled()) {
            throw new InvalidQuoteDocumentException('Dompdf security options were not applied.');
        }

        if ($options->getDefaultFont() !== QuoteDompdfOptions::DEFAULT_FONT) {
            throw new InvalidQuoteDocumentException('Dompdf default font must be DejaVu Sans.');
        }
    }
}
