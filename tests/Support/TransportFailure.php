<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class TransportFailure extends RuntimeException implements ClientExceptionInterface
{
}
