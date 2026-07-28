<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptPublicQuoteRequest;
use App\Http\Requests\RejectPublicQuoteRequest;
use App\Support\Quotes\Acceptance\QuoteCustomerResponseService;
use App\Support\Quotes\Access\QuoteCustomerAccessService;
use App\Support\Quotes\Documents\InvalidQuoteDocumentException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unauthenticated customer quote surface. No TenantContext. Never reveals whether
 * a quote exists beyond a generic unavailable response.
 */
class PublicQuoteController extends Controller
{
    public function __construct(
        private QuoteCustomerAccessService $access,
        private QuoteCustomerResponseService $responses,
    ) {}

    public function show(Request $request, string $token): Response
    {
        try {
            $opened = $this->access->open($token);
        } catch (InvalidQuoteDocumentException) {
            return $this->unavailable();
        }

        $document = $opened['document'];
        $payload = $document->customer_payload_snapshot_json ?? [];

        return $this->noStore('quotes.public.show', [
            'token' => $token,
            'quote' => $opened['quote'],
            'revision' => $opened['revision'],
            'document' => $document,
            'payload' => $payload,
            'status' => $opened['revision']->status->value,
            'canRespond' => in_array($opened['revision']->status->value, ['sent', 'viewed'], true),
        ]);
    }

    public function pdf(string $token): StreamedResponse|Response
    {
        try {
            $opened = $this->access->open($token);
        } catch (InvalidQuoteDocumentException) {
            return $this->unavailable();
        }

        $path = $opened['document']->private_pdf_path;
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return $this->unavailable();
        }

        return Storage::disk('local')->response(
            $path,
            'quote.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    public function accept(AcceptPublicQuoteRequest $request, string $token): Response
    {
        $accessToken = $this->access->resolveUsableToken($token);
        if ($accessToken === null) {
            return $this->unavailable();
        }

        try {
            $this->responses->acceptAsCustomer(
                token: $accessToken,
                typedName: $request->typedName(),
                termsAccepted: $request->termsAccepted(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (InvalidQuoteDocumentException) {
            return $this->unavailable();
        }

        return $this->noStore('quotes.public.confirmation', [
            'outcome' => 'accepted',
            'message' => __('Thank you. Your acceptance has been recorded.'),
        ]);
    }

    public function reject(RejectPublicQuoteRequest $request, string $token): Response
    {
        $accessToken = $this->access->resolveUsableToken($token);
        if ($accessToken === null) {
            return $this->unavailable();
        }

        try {
            $this->responses->rejectAsCustomer(
                token: $accessToken,
                typedName: $request->typedName(),
                rejectionReason: $request->rejectionReason(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (InvalidQuoteDocumentException) {
            return $this->unavailable();
        }

        return $this->noStore('quotes.public.confirmation', [
            'outcome' => 'rejected',
            'message' => __('Your response has been recorded.'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function noStore(string $view, array $data = [], int $status = 200): Response
    {
        return response()
            ->view($view, $data, $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function unavailable(): Response
    {
        return $this->noStore('quotes.public.unavailable', status: 404);
    }
}
