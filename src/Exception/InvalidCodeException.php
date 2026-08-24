<?php

declare(strict_types=1);

namespace Davix\Customs\Exception;

use Davix\Customs\Validation\CodeFormatResult;

final class InvalidCodeException extends CustomsException
{
    public static function forResult(CodeFormatResult $result): self
    {
        $reason = $result->failure->value ?? 'unknown';

        return new self(sprintf(
            'Commodity code "%s" is not well formed (%s).',
            $result->code,
            $reason,
        ));
    }
}