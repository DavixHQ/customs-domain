<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Support;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    /**
     * @param list<ResponseInterface|Throwable> $queue
     */
    public function __construct(private array $queue = [])
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        $next = array_shift($this->queue);

        // An exhausted queue means the client made more requests than the test
        // described. Serving a default response here would hide exactly the
        // kind of surprise - an unexpected retry, a second lookup that should
        // have been cached - that these tests exist to catch.
        if ($next === null) {
            throw new LogicException(sprintf(
                'FakeHttpClient ran out of queued responses on request %d to %s.',
                count($this->sent),
                $request->getUri(),
            ));
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function attempts(): int
    {
        return count($this->sent);
    }
}