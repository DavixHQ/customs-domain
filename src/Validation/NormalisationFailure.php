<?php

declare(strict_types=1);

namespace Davix\Customs\Validation;

/**
 * Why a raw commodity code could not be normalised.
 *
 * These are distinct from format failures. A code that normalises cleanly to
 * seven digits has not failed normalisation — it has failed validation, which
 * is CodeFormat's responsibility. Normalisation fails only when the input
 * cannot be interpreted as a single commodity code at all.
 */
enum NormalisationFailure: string
{
    /** Nothing was supplied, or only whitespace. */
    case Blank = 'blank';

    /**
     * Excel rendered a long code in scientific notation and precision is gone.
     *
     * A ten-digit code exported as 6.20140102E+09 has lost its final digits
     * beyond recovery. Guessing would produce a confident wrong classification,
     * so this is surfaced rather than repaired.
     */
    case ScientificNotation = 'scientific_notation';

    /** The value appears to contain more than one commodity code. */
    case MultipleValues = 'multiple_values';

    /** Characters remained that are neither digits nor recognised separators. */
    case NonNumeric = 'non_numeric';
}
