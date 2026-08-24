<?php

declare(strict_types=1);

namespace Davix\Customs\Validation;

use Davix\Customs\Exception\NormalisationFailedException;

/**
 * The outcome of normalising one raw commodity code.
 *
 * Immutable. Either it carries a normalised code, or it carries a failure
 * reason - never both, never neither.
 */
final class NormalisationResult
{
    /**
     * @param list<Transformation> $transformations
     */
    private function __construct(
        public readonly string $raw,
        public readonly ?string $code,
        public readonly ?NormalisationFailure $failure,
        public readonly array $transformations,
    ) {
    }

    /**
     * @param list<Transformation> $transformations
     */
    public static function success(string $raw, string $code, array $transformations = []): self
    {
        return new self($raw, $code, null, $transformations);
    }

    public static function failure(string $raw, NormalisationFailure $failure): self
    {
        return new self($raw, null, $failure, []);
    }

    public function isSuccess(): bool
    {
        return $this->code !== null;
    }

    public function isFailure(): bool
    {
        return $this->code === null;
    }

    /**
     * The normalised code.
     *
     * @throws NormalisationFailedException when normalisation did not succeed
     */
    public function code(): string
    {
        if ($this->code === null) {
            throw NormalisationFailedException::forResult($this);
        }

        return $this->code;
    }

    /**
     * True when the normalised code differs from what the merchant supplied.
     *
     * Use this to decide whether to tell them. A code that passed through
     * untouched needs no explanation.
     */
    public function wasModified(): bool
    {
        return $this->transformations !== [];
    }

    public function applied(Transformation $transformation): bool
    {
        return in_array($transformation, $this->transformations, true);
    }
}
