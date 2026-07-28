<?php

namespace App\Support\Quotes\Documents;

use RuntimeException;

/**
 * Raised when customer document generation or access prerequisites are not met.
 */
final class InvalidQuoteDocumentException extends RuntimeException {}
