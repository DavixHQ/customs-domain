<?php

declare(strict_types=1);

namespace Davix\Customs\Rule;

/**
 * A rule's declaration that its issue can be fixed, and what fixing it needs.
 *
 * Deliberately a hint rather than an executor. Applying a fix writes to
 * storage this package knows nothing about, has to run asynchronously at any
 * real catalogue size, and needs an undo log - all host concerns. A domain
 * interface with an apply() method would be one no non-Magento consumer could
 * implement sensibly.
 *
 * So the rule says what could be done and supplies the data needed to do it.
 * The host decides how.
 */
final class RemediationHint
{
    /**
     * @param string $code Identifies the kind of fix, e.g. 'replace_with_successor'
     * @param bool $automatic True when the fix needs no merchant input, because
     *                        the answer is already known - a withdrawn code with
     *                        exactly one successor. These earn a one-click button;
     *                        everything else opens a form.
     * @param array<string, string|int|float|bool|null> $payload Data the host needs
     *                        to apply the fix, such as the successor code.
     */
    public function __construct(
        public readonly string $code,
        public readonly bool $automatic = false,
        public readonly array $payload = [],
    ) {
    }

    /**
     * @param array<string, string|int|float|bool|null> $payload
     */
    public static function automatic(string $code, array $payload = []): self
    {
        return new self($code, true, $payload);
    }

    /**
     * @param array<string, string|int|float|bool|null> $payload
     */
    public static function requiresInput(string $code, array $payload = []): self
    {
        return new self($code, false, $payload);
    }

    /**
     * Translation key for the action label, e.g. "Replace with successor code".
     */
    public function labelKey(): string
    {
        return sprintf('remediation.%s.label', $this->code);
    }

    public function isAutomatic(): bool
    {
        return $this->automatic;
    }
}
