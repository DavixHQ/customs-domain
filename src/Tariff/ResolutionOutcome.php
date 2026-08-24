<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * What happened when a merchant's code was resolved against the mirror.
 *
 * Each outcome leads somewhere different in the UI, which is why they are not
 * collapsed into found/not-found. "We cannot check this chapter" and "this
 * code does not exist" look identical to a naive resolver and could not be
 * more different to a merchant.
 */
enum ResolutionOutcome: string
{
    /** Exactly one declarable line. Nothing further is needed. */
    case Resolved = 'resolved';

    /**
     * The code is real but describes a group. More than one declarable line
     * sits beneath it and only the merchant can say which applies.
     */
    case Ambiguous = 'ambiguous';

    /**
     * Absent from the mirror. Could be a typo or a code withdrawn in a past
     * revision - distinguishing those needs a historic lookup against the
     * provider, which is not this class's business.
     */
    case NotInMirror = 'not_in_mirror';

    /**
     * The sync has not successfully pulled this chapter, so absence proves
     * nothing. Reporting these as unknown codes would flag every product in
     * the chapter over a failure that is entirely the module's own.
     */
    case ChapterNotMirrored = 'chapter_not_mirrored';

    /**
     * A national chapter - 98 or 99 - that the standard nomenclature sync
     * never covers. Real codes, just not ones the mirror was going to hold.
     */
    case OutsideStandardNomenclature = 'outside_standard_nomenclature';

    /**
     * The line exists but is not declarable and has nothing declarable
     * beneath it. A dead end, which normally means the mirror is incomplete
     * rather than that the merchant did anything wrong.
     */
    case DeadEnd = 'dead_end';

    public function isSuccessful(): bool
    {
        return $this === self::Resolved;
    }

    /**
     * Whether the mirror was in a position to give a trustworthy answer.
     *
     * False means the module could not check, rather than that the code is
     * wrong - the distinction that keeps a failed sync from being reported as
     * thousands of bad commodity codes.
     */
    public function isConclusive(): bool
    {
        return $this !== self::ChapterNotMirrored
            && $this !== self::OutsideStandardNomenclature;
    }
}
