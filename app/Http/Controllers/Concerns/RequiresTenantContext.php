<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Tenancy\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait RequiresTenantContext
{
    protected function requireTenantContext(): TenantContext
    {
        if (! TenantContext::has()) {
            throw new HttpException(
                409,
                'An organization context is required for this action.',
            );
        }

        return TenantContext::get();
    }
}
