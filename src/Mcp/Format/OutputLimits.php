<?php
namespace McpServer\Mcp\Format;

/**
 * The limits on how much a tool returns, and the functions that keep output
 * within them: cutting long text, marking what was cut, and leaving out empty
 * fields.
 *
 * It holds the limits on how much of a resource a tool shows, and the highest
 * page any tool takes. Limits on how many resources a call returns or counts
 * live with the code that applies them: the tools' page sizes, including
 * GetOverview::PER_PAGE, AbstractSearchTool::PROPERTIES_PAGE_LENGTH_MAX, and
 * ResourceCounter::COUNT_MAX.
 *
 * The rule in ToolInterface says nothing is cut silently. The formatters use
 * these limits and marks when they cut, and OutputLimitNotes reads the marks
 * to tell the model what was cut.
 */
final class OutputLimits
{
    // Longest description in a summary, and longest site summary.
    const DESCRIPTION_LIMIT = 300;

    // Longest text of a single value before it's cut.
    const TEXT_LIMIT = 2000;

    // Most media an item lists; its media_total says when there are more.
    const MEDIA_LIMIT = 50;

    // Most entries of one list, such as a property's values or the links from
    // other items, before it's cut.
    const LIST_LIMIT = 100;

    // Most values of one property a search result shows, when a search names
    // properties to show.
    const PROPERTY_VALUES_LIMIT = 10;

    // Most characters of one text read at a time, from an offset, to read
    // the rest of a text cut at TEXT_LIMIT.
    const CHUNK_LIMIT = 10000;

    // Highest page number any tool takes, which keeps offsets well within
    // range: 500,000 results at the searches' largest page size.
    const PAGE_MAX = 10000;

    // What ends a text cut at TEXT_LIMIT, with its full length.
    const CUT_MARKER = '[truncated, %s characters]';

    // The keys that mark a cut in the data. The formatters write them, and
    // the tools' notes read them to say what was cut and how to reach it.
    // A list's full length, when it was cut or is read a page at a time.
    const TOTAL = 'total';

    // That a text was cut at TEXT_LIMIT.
    const TRUNCATED = 'truncated';

    // How many media an item has, when it lists only MEDIA_LIMIT.
    const MEDIA_TOTAL = 'media_total';

    // The full length of each list in module data that was cut.
    const MODULE_DATA_TOTALS = 'module_data_totals';

    // That a text in module data was cut.
    const MODULE_DATA_TRUNCATED = 'module_data_truncated';

    // The full length of each property a search result cut, and that a value
    // in one was shortened.
    const PROPERTIES_TOTALS = 'properties_totals';

    const PROPERTIES_TRUNCATED = 'properties_truncated';

    /**
     * Cut text longer than TEXT_LIMIT, saying how long it was.
     */
    public static function cut(string $text): string
    {
        $length = mb_strlen($text);
        if ($length <= self::TEXT_LIMIT) {
            return $text;
        }
        return sprintf('%s… ' . self::CUT_MARKER, mb_substr($text, 0, self::TEXT_LIMIT), $length);
    }

    /**
     * Shorten text for a summary, at a word boundary.
     */
    public static function shorten(string $text, int $limit): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $text = mb_substr($text, 0, $limit);
        $space = mb_strrpos($text, ' ');
        if (false !== $space && $space > $limit / 2) {
            $text = mb_substr($text, 0, $space);
        }
        return rtrim($text, " \t\n,;:.") . '…';
    }

    /**
     * Get part of a text, from a character offset, CHUNK_LIMIT characters
     * long at most.
     *
     * @return array ['length', 'text']: the text's full length, and the part
     */
    public static function chunk(string $text, int $offset): array
    {
        return ['length' => mb_strlen($text), 'text' => mb_substr($text, $offset, self::CHUNK_LIMIT)];
    }

    /**
     * Leave out empty fields, which only cost the model tokens.
     */
    public static function withoutEmpty(array $fields): array
    {
        return array_filter($fields, fn ($field) => null !== $field && '' !== $field && [] !== $field);
    }
}
