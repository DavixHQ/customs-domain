<?php

declare(strict_types=1);

namespace Davix\Customs\Validation;

/**
 * Why a code is not well formed.
 *
 * Each case exists because it warrants a different message and, in several
 * cases, a different remediation. "You have given us a heading rather than a
 * commodity code" and "this is not a number" are both invalid, but a merchant
 * can act on the first immediately and the second needs investigation.
 */
enum FormatFailure: string
{
    /** Nothing was supplied. */
    case Blank = 'blank';

    /** Characters other than digits are present. */
    case NotNumeric = 'not_numeric';

    /**
     * A real level in the hierarchy, but too coarse to identify goods.
     *
     * Chapter 62 or heading 6201 describe a category, not a product. The fix
     * is classification, not correction.
     */
    case TooShort = 'too_short';

    /**
     * Commodity codes have an even number of digits at every level.
     *
     * After normalisation this only survives above ten digits, since shorter
     * odd lengths are treated as a stripped leading zero and padded.
     */
    case OddLength = 'odd_length';

    /** Longer than the ten-digit commodity level. */
    case TooLong = 'too_long';

    /**
     * The chapter does not exist.
     *
     * Chapter 00 is not a chapter, and chapter 77 is reserved for possible
     * future international use and currently holds no goods.
     */
    case UnknownChapter = 'unknown_chapter';
}
