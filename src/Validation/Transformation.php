<?php

declare(strict_types=1);

namespace Davix\Customs\Validation;

/**
 * A change the normaliser made to the merchant's raw input.
 *
 * Recorded so the UI can say what it did. "We read 0101210000 from your value
 * of 101210000 — Excel appears to have removed the leading zero" is a very
 * different merchant experience from silently altering their data.
 */
enum Transformation: string
{
    case TrimmedWhitespace = 'trimmed_whitespace';
    case RemovedQuoting = 'removed_quoting';
    case RemovedSeparators = 'removed_separators';
    case DroppedDecimalZeroes = 'dropped_decimal_zeroes';
    case PaddedLeadingZero = 'padded_leading_zero';
}
