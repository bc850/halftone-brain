<?php

namespace App\Support\Quotes;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class StaleQuoteStateException extends HttpException
{
    public function __construct(string $message = 'Quote state is stale.')
    {
        parent::__construct(409, $message);
    }
}
