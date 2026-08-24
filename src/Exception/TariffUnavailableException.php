<?php

declare(strict_types=1);

namespace Davix\Customs\Exception;

use Throwable;

/**
 * The tariff service could not be reached, or answered with a failure.
 *
 * Distinct from a parse failure. This one means "ask again later", and a scan
 * seeing it should mark affected rules as unevaluated rather than reporting
 * every product as non-compliant.
 */
final class TariffUnavailableException extends CustomsException
{
    /**
     * @param float|null $retryAfterSeconds What the service asked us to wait,
     *        when it said. Carried on the exception rather than tracked
     *        alongside it, so the instruction cannot become detached from the
     *        failure it belongs to.
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
        public readonly ?float $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    /**
     * A copy carrying the service's own retry instruction.
     */
    public function withRetryAfter(?float $seconds): self
    {
        if ($seconds === null) {
            return $this;
        }

        return new self(
            $this->getMessage(),
            $this->statusCode,
            $this->retryable,
            $this->getPrevious(),
            max(0.0, $seconds),
        );
    }

    public static function transport(string $url, Throwable $previous): self
    {
        return new self(
            sprintf('Could not reach the tariff service at %s: %s', $url, $previous->getMessage()),
            null,
            true,
            $previous,
        );
    }

    public static function status(string $url, int $statusCode): self
    {
        return new self(
            sprintf('The tariff service returned %d for %s.', $statusCode, $url),
            $statusCode,
            $statusCode === 429 || $statusCode >= 500,
        );
    }

    public static function notFound(string $url): self
    {
        return new self(
            sprintf('The tariff service has nothing at %s.', $url),
            404,
            false,
        );
    }

    public static function exhausted(string $url, int $attempts, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Gave up on %s after %d attempts.', $url, $attempts),
            null,
            true,
            $previous,
        );
    }

    public static function malformed(string $url, Throwable $previous): self
    {
        return new self(
            sprintf('The tariff service returned something unreadable for %s.', $url),
            null,
            false,
            $previous,
        );
    }
}
