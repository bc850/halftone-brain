<?php

namespace App\Http\Resources;

use App\Models\QuoteRevisionDocument;

/**
 * Customer document metadata for internal UIs. Never exposes private storage paths.
 */
final class QuoteDocumentResource
{
    /**
     * @param  iterable<int, QuoteRevisionDocument>  $documents
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $documents): array
    {
        $payload = [];

        foreach ($documents as $document) {
            $payload[] = self::make($document);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(?QuoteRevisionDocument $document): ?array
    {
        if ($document === null) {
            return null;
        }

        return [
            'id' => $document->id,
            'document_type' => $document->document_type->value,
            'document_version' => $document->document_version,
            'generation_status' => $document->generation_status->value,
            'mime_type' => $document->mime_type,
            'byte_size' => $document->byte_size,
            'content_sha256' => $document->content_sha256,
            'generated_at' => $document->generated_at?->toIso8601String(),
            'failure_code' => $document->failure_code,
            'failure_message' => $document->failure_message,
            'has_pdf' => $document->private_pdf_path !== null,
            'has_html' => $document->private_html_path !== null,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
