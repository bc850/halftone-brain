<?php

namespace App\Support\Quotes;

use App\Models\QuoteRevision;
use App\Models\QuoteRevisionAdjustment;
use App\Models\QuoteRevisionLineItem;
use App\Models\QuoteRevisionPartySnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * Copies a revision's party snapshot, line items, and adjustments onto a freshly cloned draft.
 *
 * Children are copied verbatim (new IDs, identical positions and snapshots) so the new draft
 * starts as an exact restatement of the source. Line-level approval flags are part of the
 * snapshot and carry over; revision-header approval and tax state are reset by
 * {@see QuoteRevisionCloner}.
 */
final class QuoteRevisionChildrenCloner
{
    /**
     * Callable form for {@see QuoteRevisionCloner::cloneToDraft()}'s `afterCreate` hook.
     */
    public function __invoke(QuoteRevision $newRevision, QuoteRevision $source): void
    {
        $this->copy($source, $newRevision);
    }

    public function copy(QuoteRevision $source, QuoteRevision $target): void
    {
        $snapshot = QuoteRevisionPartySnapshot::query()
            ->where('quote_revision_id', $source->id)
            ->first();

        if ($snapshot !== null) {
            $this->copyRow($snapshot, $target);
        }

        $lines = QuoteRevisionLineItem::query()
            ->where('quote_revision_id', $source->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($lines as $line) {
            $this->copyRow($line, $target);
        }

        $adjustments = QuoteRevisionAdjustment::query()
            ->where('quote_revision_id', $source->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($adjustments as $adjustment) {
            $this->copyRow($adjustment, $target);
        }
    }

    /**
     * Replicate copies raw attributes, so JSON snapshot columns are never re-encoded.
     */
    private function copyRow(Model $row, QuoteRevision $target): void
    {
        $copy = $row->replicate();
        $copy->forceFill([
            'parent_account_id' => $target->parent_account_id,
            'organization_id' => $target->organization_id,
            'quote_id' => $target->quote_id,
            'quote_revision_id' => $target->id,
        ]);
        $copy->save();
    }
}
