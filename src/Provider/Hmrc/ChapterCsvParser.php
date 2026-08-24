<?php

declare(strict_types=1);

namespace Davix\Customs\Provider\Hmrc;

use Davix\Customs\Exception\TariffParseException;
use Davix\Customs\Tariff\Commodity;
use DateTimeImmutable;
use Generator;

/**
 * Parses a chapter's nomenclature from the tariff's CSV form.
 *
 * The CSV is worth preferring over the JSON for the mirror sync: chapter 62 is
 * 64 KB as CSV against 221 KB as JSON, which over 97 chapters is roughly 6 MB
 * per full sync instead of 20 MB. It also carries the parent SID as a plain
 * column rather than a nested relationship, so the tree arrives already
 * linked.
 *
 * Streamed rather than loaded whole. Some chapters run to thousands of lines
 * and a merchant's first sync should not be a memory spike.
 *
 * Two details the format demands and documentation does not mention. Dates
 * arrive as `1972-01-01 00:00:00 UTC`, which is not an ISO string. And every
 * end date in a chapter pull is empty, because filtering by `as_of` returns
 * only lines valid on that date — so a null end date means "in force", never
 * "unknown", and a code that has been withdrawn is simply absent.
 */
final class ChapterCsvParser
{
    private const COLUMN_SID = 'SID';
    private const COLUMN_CODE = 'Goods Nomenclature Item ID';
    private const COLUMN_INDENTS = 'Indents';
    private const COLUMN_DESCRIPTION = 'Description';
    private const COLUMN_SUFFIX = 'Product Line Suffix';
    private const COLUMN_HREF = 'Href';
    private const COLUMN_START = 'Start date';
    private const COLUMN_END = 'End date';
    private const COLUMN_DECLARABLE = 'Declarable';
    private const COLUMN_PARENT = 'Parent SID';

    /** Columns without which a row cannot be turned into a commodity. */
    private const REQUIRED_COLUMNS = [
        self::COLUMN_SID,
        self::COLUMN_CODE,
        self::COLUMN_SUFFIX,
        self::COLUMN_DESCRIPTION,
    ];

    /**
     * Parse a whole chapter into commodities.
     *
     * @return list<Commodity>
     * @throws TariffParseException when the header is missing or unusable
     */
    public function parse(string $csv): array
    {
        return iterator_to_array($this->stream($csv), false);
    }

    /**
     * Parse row by row, holding one line in memory at a time.
     *
     * Declared as Generator rather than iterable because it is one, and
     * because PHP 8.1's iterator_to_array() accepts only Traversable — the
     * widening to iterable landed in 8.2. Returning the looser type compiles
     * everywhere and then fails static analysis on the oldest version this
     * package supports.
     *
     * @return Generator<int, Commodity>
     * @throws TariffParseException when the header is missing or unusable
     */
    public function stream(string $csv): Generator
    {
        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw TariffParseException::unreadableStream();
        }

        try {
            fwrite($handle, $csv);
            rewind($handle);

            yield from $this->streamHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parse from an open stream, which is how a real sync should read a
     * response rather than materialising the whole body as a string first.
     *
     * @param resource $handle
     * @return Generator<int, Commodity>
     * @throws TariffParseException when the header is missing or unusable
     */
    public function streamHandle($handle): Generator
    {
        $header = fgetcsv($handle);

        if ($header === false || $header === [null]) {
            throw TariffParseException::emptyChapter();
        }

        $columns = $this->indexHeader($header);

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue;
            }

            $commodity = $this->mapRow($row, $columns);

            if ($commodity !== null) {
                yield $commodity;
            }
        }
    }

    /**
     * @param list<string|null> $header
     * @return array<string, int>
     * @throws TariffParseException
     */
    private function indexHeader(array $header): array
    {
        $columns = [];

        foreach ($header as $position => $name) {
            if (is_string($name)) {
                // Strip a UTF-8 byte order mark, which arrives on the first
                // column name and would otherwise make it unmatchable.
                $columns[trim(str_replace("\xEF\xBB\xBF", '', $name))] = $position;
            }
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($columns)));

        if ($missing !== []) {
            throw TariffParseException::missingColumns($missing);
        }

        return $columns;
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $columns
     */
    private function mapRow(array $row, array $columns): ?Commodity
    {
        $sid = $this->intAt($row, $columns, self::COLUMN_SID);
        $code = $this->stringAt($row, $columns, self::COLUMN_CODE);

        // A row without an identity is not a partial commodity, it is noise —
        // a trailing blank line or a malformed record. Skipping is kinder than
        // failing an entire chapter over one bad line.
        if ($sid === null || $code === null) {
            return null;
        }

        return new Commodity(
            sid: $sid,
            code: $code,
            productlineSuffix: $this->stringAt($row, $columns, self::COLUMN_SUFFIX) ?? '',
            description: $this->stringAt($row, $columns, self::COLUMN_DESCRIPTION) ?? '',
            declarable: $this->boolAt($row, $columns, self::COLUMN_DECLARABLE),
            parentSid: $this->intAt($row, $columns, self::COLUMN_PARENT),
            numberIndents: $this->intAt($row, $columns, self::COLUMN_INDENTS) ?? 0,
            validityStart: $this->dateAt($row, $columns, self::COLUMN_START),
            validityEnd: $this->dateAt($row, $columns, self::COLUMN_END),
            supplementaryUnit: null,
            href: $this->stringAt($row, $columns, self::COLUMN_HREF),
        );
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $columns
     */
    private function stringAt(array $row, array $columns, string $column): ?string
    {
        $position = $columns[$column] ?? null;

        if ($position === null) {
            return null;
        }

        $value = $row[$position] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $columns
     */
    private function intAt(array $row, array $columns, string $column): ?int
    {
        $value = $this->stringAt($row, $columns, $column);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $columns
     */
    private function boolAt(array $row, array $columns, string $column): bool
    {
        return strtolower((string) $this->stringAt($row, $columns, $column)) === 'true';
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $columns
     */
    private function dateAt(array $row, array $columns, string $column): ?DateTimeImmutable
    {
        $value = $this->stringAt($row, $columns, $column);

        if ($value === null) {
            return null;
        }

        // "1972-01-01 00:00:00 UTC" is not ISO 8601, so the explicit format
        // comes first; the fallback catches any variant the tariff adopts later.
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s T', $value);

        if ($parsed !== false) {
            return $parsed;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}