<?php

namespace App\Support\Tax;

use InvalidArgumentException;

/**
 * Raised when a tax profile, rate, or exemption certificate change is structurally
 * invalid: a duplicate profile, a rate edit that would rewrite history, a
 * certificate transition that is not legal, or a missing required justification.
 */
final class InvalidTaxConfigurationException extends InvalidArgumentException {}
