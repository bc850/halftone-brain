<?php

namespace App\Support\Quotes\Approval;

use RuntimeException;

/**
 * Raised when an approval step is asked for in a state that cannot support it: an
 * unresolved tax position, a request that is already resolved, a revision that is not
 * awaiting approval, or a rejection with no reason.
 */
final class InvalidQuoteApprovalException extends RuntimeException {}
