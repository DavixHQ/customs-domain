<?php

declare(strict_types=1);

namespace Davix\Customs\Validation;

use Davix\Customs\Exception\InvalidCodeException;

/**
 * The outcome of checking one code's format.
 *
 * Either it carries the level the code sits at, or it carries a failure
 * reason - never both, never neither.
 */
final class CodeFormatResult
{
    private function __construct(
        public readonly string $code,
        public readonly ?CodeLevel $level,
        public readonly ?FormatFailure $failure,
    ) {
    }

    public static function valid(string $code, CodeLevel $level): self
    {
        return new self($code, $level, null);
    }

    public static function invalid(string $code, FormatFailure $failure): self
    {
        return new self($code, null, $failure);
    }

    public function isValid(): bool
    {
        return $this->level !== null;
    }

    public function isInvalid(): bool
    {
        return $this->level === null;
    }

    /**
     * @throws InvalidCodeException when the code is not well formed
     */
    public function level(): CodeLevel
    {
        if ($this->level === null) {
            throw InvalidCodeException::forResult($this);
        }

        return $this->level;
    }

    /**
     * True when the code is well formed but not specific enough to declare.
     *
     * This is the signal that a code needs expanding rather than correcting,
     * and it is the boundary between the format rule and the ambiguous
     * expansion rule.
     */
    public function needsExpansion(): bool
    {
        return $this->level !== null && !$this->level->isDeclarable();
    }
}
