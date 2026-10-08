<?php

namespace App\Services\Ingestion;

use RuntimeException;

/**
 * Internal signal: the batch UUID was committed by a concurrent request.
 */
class BatchAlreadyReceived extends RuntimeException {}
