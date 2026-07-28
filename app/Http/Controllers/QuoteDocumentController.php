<?php

namespace App\Http\Controllers;

use App\Enums\QuoteDocumentGenerationStatus;
use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\GenerateQuoteDocumentRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Support\Quotes\Documents\QuoteDocumentGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuoteDocumentController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteDocumentGenerationService $documents) {}

    public function generate(
        GenerateQuoteDocumentRequest $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
    ): RedirectResponse {
        $this->prepare($quote, $quoteRevision, 'generateDocument');

        $this->runDraftMutation(fn (): QuoteRevisionDocument => $this->documents->generate(
            quote: $quote,
            revision: $quoteRevision,
            actor: $request->user(),
            actorMembership: $this->actingMembership(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer document generated.')]);

        return back();
    }

    public function preview(
        Request $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
        QuoteRevisionDocument $document,
    ): Response|StreamedResponse {
        $this->prepare($quote, $quoteRevision, 'view');
        $this->assertDocumentReady($document);

        if ($request->query('format') === 'pdf') {
            return $this->streamPdf($document, inline: true);
        }

        $path = $document->private_html_path;
        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function download(
        Request $request,
        ?Organization $organization,
        Quote $quote,
        QuoteRevision $quoteRevision,
        QuoteRevisionDocument $document,
    ): StreamedResponse {
        $this->prepare($quote, $quoteRevision, 'view');
        $this->assertDocumentReady($document);

        return $this->streamPdf($document, inline: false);
    }

    private function prepare(Quote $quote, QuoteRevision $revision, string $ability): void
    {
        $this->requireTenantContext();
        $this->authorize($ability, $quote);
        $this->assertRevisionBelongsToQuote($quote, $revision);
    }

    private function assertDocumentReady(QuoteRevisionDocument $document): void
    {
        abort_unless(
            $document->generation_status === QuoteDocumentGenerationStatus::Generated,
            404,
        );
    }

    private function streamPdf(QuoteRevisionDocument $document, bool $inline): StreamedResponse
    {
        $path = $document->private_pdf_path;
        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        $disposition = $inline ? 'inline' : 'attachment';
        $filename = 'quote-v'.$document->document_version.'.pdf';

        return Storage::disk('local')->response(
            $path,
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    private function actingMembership(): Membership
    {
        return Membership::query()->findOrFail($this->requireTenantContext()->organizationMembershipId);
    }
}
