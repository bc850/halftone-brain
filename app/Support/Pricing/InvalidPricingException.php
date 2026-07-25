<?php

namespace App\Support\Pricing;

use RuntimeException;

/**
 * Domain validation failure for pricing configuration or calculation inputs.
 *
 * Messages are suitable for later form-validation mapping in 1C.
 */
final class InvalidPricingException extends RuntimeException {}
