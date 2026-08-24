<?php

declare(strict_types=1);

namespace Davix\Customs\Exception;

use RuntimeException;

/**
 * Base class for every exception this package throws.
 *
 * Consumers can catch this one type to isolate domain failures from their own
 * platform's exceptions.
 */
abstract class CustomsException extends RuntimeException
{
}
