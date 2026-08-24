<?php

declare(strict_types=1);

namespace Davix\Customs\Exception;

final class RuleConfigurationException extends CustomsException
{
    public static function duplicateCode(string $code): self
    {
        return new self(sprintf(
            'Two rules are registered under the code "%s". Rule codes are persisted '
            . 'against issues and used in grid filters, so they must be unique.',
            $code,
        ));
    }

    public static function unknownPrerequisite(string $ruleCode, string $prerequisite): self
    {
        return new self(sprintf(
            'Rule "%s" requires "%s", which is not registered.',
            $ruleCode,
            $prerequisite,
        ));
    }

    /**
     * @param list<string> $cycle
     */
    public static function circularPrerequisites(array $cycle): self
    {
        return new self(sprintf(
            'Rule prerequisites form a cycle: %s.',
            implode(' -> ', $cycle),
        ));
    }

    public static function selfPrerequisite(string $ruleCode): self
    {
        return new self(sprintf('Rule "%s" lists itself as a prerequisite.', $ruleCode));
    }
}
