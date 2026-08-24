<?php

declare(strict_types=1);

namespace Davix\Customs\Rule;

/**
 * What a rule emits when a product fails it.
 *
 * Carries a translation key and a context array rather than a finished
 * sentence. This package cannot translate — it has no framework and no locale
 * — and baking English into it would make every consumer inherit one language.
 * The host renders `rule.withdrawn_code.message` with the supplied context.
 *
 * Deliberately holds no product reference. A rule is a pure function over one
 * product's data, and the caller already knows which product it passed in.
 * Keeping identity out makes issues comparable and rules trivially testable.
 */
final class Issue
{
    /**
     * @param array<string, string|int|float|bool|null> $context Values for
     *        interpolation into the message, and structured data the UI can
     *        act on — the successor code, the candidate count, the raw value
     *        the merchant typed.
     * @param string|null $variant Distinguishes wordings of the same rule.
     *        A code withdrawn with one successor deserves a different sentence
     *        from one withdrawn with none.
     */
    public function __construct(
        public readonly string $ruleCode,
        public readonly Severity $severity,
        public readonly array $context = [],
        public readonly ?string $variant = null,
        public readonly ?RemediationHint $remediation = null,
    ) {
    }

    /**
     * Translation key for the merchant-facing message.
     */
    public function messageKey(): string
    {
        if ($this->variant === null) {
            return sprintf('rule.%s.message', $this->ruleCode);
        }

        return sprintf('rule.%s.message.%s', $this->ruleCode, $this->variant);
    }

    /**
     * Translation key for the longer explanation shown in the drawer.
     */
    public function explanationKey(): string
    {
        return sprintf('rule.%s.explanation', $this->ruleCode);
    }

    public function isFixable(): bool
    {
        return $this->remediation !== null;
    }

    public function isAutomaticallyFixable(): bool
    {
        return $this->remediation !== null && $this->remediation->isAutomatic();
    }

    /**
     * Return a copy carrying a different severity.
     *
     * Used by the pool to apply a merchant's severity override without the
     * rule needing to know its configuration.
     */
    public function withSeverity(Severity $severity): self
    {
        if ($severity === $this->severity) {
            return $this;
        }

        return new self(
            $this->ruleCode,
            $severity,
            $this->context,
            $this->variant,
            $this->remediation,
        );
    }

    /**
     * @return string|int|float|bool|null
     */
    public function contextValue(string $key): string|int|float|bool|null
    {
        return $this->context[$key] ?? null;
    }
}
