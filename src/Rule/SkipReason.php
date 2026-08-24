<?php

declare(strict_types=1);

namespace Davix\Customs\Rule;

/**
 * Why a rule did not run against a product.
 */
enum SkipReason: string
{
    /** The merchant switched this rule off. */
    case Disabled = 'disabled';

    /**
     * A rule this one depends on emitted an issue, so running this one would
     * produce noise derived from an already-reported problem.
     */
    case PrerequisiteFailed = 'prerequisite_failed';

    /**
     * A rule this one depends on was itself skipped, so its result is unknown
     * rather than passing. Skipping cascades.
     */
    case PrerequisiteSkipped = 'prerequisite_skipped';
}
