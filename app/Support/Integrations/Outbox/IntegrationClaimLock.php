<?php

namespace App\Support\Integrations\Outbox;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class IntegrationClaimLock
{
    /**
     * Apply MySQL-safe SKIP LOCKED when supported; SQLite ignores locks.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->supportsSkipLocked()) {
            return $query->lock('for update skip locked');
        }

        return $query->lockForUpdate();
    }

    public function supportsSkipLocked(): bool
    {
        return (string) config('database.connections.'.config('database.default').'.driver') === 'mysql';
    }
}
