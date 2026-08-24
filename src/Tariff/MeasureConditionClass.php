<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * How a measure condition qualifies its measure.
 *
 * Values observed on live responses. `unknown` is real and appears in the
 * payload, so it is modelled rather than treated as a parse failure.
 */
enum MeasureConditionClass: string
{
    /** A certificate, licence or statement must be presented. */
    case Document = 'document';

    /** What happens when the condition is not met - often a prohibition. */
    case Negative = 'negative';

    /** The goods fall outside the measure. */
    case Exemption = 'exemption';

    /** The measure applies above or below some quantity or value. */
    case Threshold = 'threshold';

    /** Present in live data. Carried through rather than discarded. */
    case Unknown = 'unknown';

    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Unknown;
    }
}
