<?php

declare(strict_types=1);

namespace Davix\Customs\Exception;

use Davix\Customs\Validation\NormalisationResult;

final class NormalisationFailedException extends CustomsException
{
    public static function forResult(NormalisationResult $result): self
    {
        $reason = $result->failure->value ?? 'unknown';

        return new self(sprintf(
            'Cannot read a commodity code from "%s" (%s).',
            $result->raw,
            $reason,
        ));
    }
}