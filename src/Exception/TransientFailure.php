<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

/**
 * Marker interface for failures that are safe to retry (timeouts, connection
 * resets, HTTP 5xx, HTTP 408/429). Validation and 4xx rejections must NOT
 * implement this interface.
 */
interface TransientFailure
{
}
