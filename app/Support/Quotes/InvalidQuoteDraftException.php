<?php

namespace App\Support\Quotes;

use InvalidArgumentException;

/**
 * Raised when a draft quote mutation is structurally invalid (bad payload,
 * cross-tenant reference, missing override authority, or ineligible catalog item).
 */
final class InvalidQuoteDraftException extends InvalidArgumentException {}
