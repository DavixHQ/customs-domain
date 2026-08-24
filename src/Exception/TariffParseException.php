<?php

declare(strict_types=1);

namespace Davix\Customs\Exception;

final class TariffParseException extends CustomsException
{
    public static function emptyChapter(): self
    {
        return new self(
            'The chapter response contained no header row. A sync should treat '
            . 'this as a failed pull rather than an empty chapter, or the mirror '
            . 'will lose every line it already held.',
        );
    }

    /**
     * @param list<string> $columns
     */
    public static function missingColumns(array $columns): self
    {
        return new self(sprintf(
            'The chapter response is missing required column(s): %s.',
            implode(', ', $columns),
        ));
    }

    public static function unreadableStream(): self
    {
        return new self('Could not open a stream to read the chapter response.');
    }
}
