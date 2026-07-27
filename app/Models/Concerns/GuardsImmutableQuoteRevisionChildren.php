<?php

namespace App\Models\Concerns;

use App\Models\QuoteRevision;
use App\Support\Quotes\ImmutableQuoteRevisionException;

trait GuardsImmutableQuoteRevisionChildren
{
    protected static function bootGuardsImmutableQuoteRevisionChildren(): void
    {
        static::creating(function (self $model): void {
            $model->assertParentRevisionMutable('create');
        });

        static::updating(function (self $model): void {
            $model->assertParentRevisionMutable('update');
        });

        static::deleting(function (self $model): void {
            $model->assertParentRevisionMutable('delete');
        });
    }

    private function assertParentRevisionMutable(string $operation): void
    {
        $revisionId = $this->quote_revision_id ?? null;
        if ($revisionId === null) {
            return;
        }

        $revision = QuoteRevision::query()->find($revisionId);
        if ($revision === null) {
            return;
        }

        $status = $revision->status;
        if ($status->isCustomerContentImmutable()) {
            throw new ImmutableQuoteRevisionException(
                sprintf(
                    '%s cannot %s while quote revision [%d] is %s.',
                    class_basename(static::class),
                    $operation,
                    $revision->id,
                    $status->value,
                )
            );
        }
    }
}
