<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * What a merchant has to hold to claim a preferential rate.
 *
 * The half of the answer that is actually actionable. Knowing the goods
 * qualify is no use without knowing that the claim needs an EUR.1 certificate,
 * or a statement on the invoice, or nothing more than importer's knowledge -
 * and those are very different amounts of work to arrange with a supplier.
 */
class OriginProof
{
    public function __construct(
        public readonly string $summary,
        public readonly ?string $subtext = null,
        public readonly ?string $content = null,
        public readonly ?string $url = null,
    ) {
    }

    /**
     * A short explanation, trimmed to something that fits beside the summary.
     */
    public function shortContent(int $length = 240): ?string
    {
        if ($this->content === null || trim($this->content) === '') {
            return null;
        }

        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($this->content)));

        if (mb_strlen($plain) <= $length) {
            return $plain;
        }

        $cut = mb_substr($plain, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        return ($lastSpace === false ? $cut : mb_substr($cut, 0, $lastSpace)) . '...';
    }
}