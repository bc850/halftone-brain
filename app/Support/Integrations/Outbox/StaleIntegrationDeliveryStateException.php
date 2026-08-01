<?php

namespace App\Support\Integrations\Outbox;

use RuntimeException;

final class StaleIntegrationDeliveryStateException extends RuntimeException {}
