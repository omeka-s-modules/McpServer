<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Format\OutputLimits;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Writes the notes that tell the model what a tool's output cut, and how to
 * reach the rest, as the rule in ToolInterface asks.
 *
 * The formatters mark each cut in the data, with the keys OutputLimits names.
 * These notes read those marks, and say which argument or tool reaches what
 * was cut, or that nothing does.
 */
final class OutputLimitNotes
{
    /**
     * Say what a concise form cut, such as get_item's, and how to reach the
     * rest.
     *
     * The data marks each cut: a property's total, media_total,
     * module_data_totals, a truncated flag, or data_truncated.
     */
    public static function forConcise(array $output): ?string
    {
        $notes = [];
        $cutProperties = [];
        $readable = [];
        $annotationTextsCut = false;
        $annotationListsCut = false;
        foreach ($output['values'] ?? [] as $term => $property) {
            if (isset($property[OutputLimits::TOTAL])) {
                $cutProperties[] = sprintf('%s has %d', $term, $property[OutputLimits::TOTAL]);
            }
            [$positions, $inAnnotations] = self::findCutTexts($property['values'], 1);
            foreach ($positions as $position) {
                $readable[] = [$term, $position];
            }
            $annotationTextsCut = $annotationTextsCut || $inAnnotations;
            $annotationListsCut = $annotationListsCut || self::hasCutAnnotationList($property['values']);
        }
        if (!empty($output['content'][OutputLimits::TRUNCATED])) {
            $readable[] = ['content', null];
        }
        if ($cutProperties) {
            $notes[] = sprintf(
                'Only the first %d values of a property are shown, and %s. Call again with "property" to read all of a property\'s values.',
                OutputLimits::LIST_LIMIT, implode(', ', $cutProperties)
            );
        }
        if (isset($output[OutputLimits::MEDIA_TOTAL]) && $output[OutputLimits::MEDIA_TOTAL] > count($output['media'] ?? [])) {
            $notes[] = sprintf(
                'Only the first %d of the %d media are listed. search_media with "item" %d lists them all.',
                count($output['media'] ?? []), $output[OutputLimits::MEDIA_TOTAL], $output['id']
            );
        }
        if (!empty($output[OutputLimits::MODULE_DATA_TOTALS])) {
            $notes[] = sprintf(
                'Only the first %d entries of a list in module_data are shown, and %s. The rest can\'t be read through these tools.',
                OutputLimits::LIST_LIMIT,
                implode(', ', array_map(fn ($key, $total) => sprintf('%s has %d', $key, $total), array_keys($output[OutputLimits::MODULE_DATA_TOTALS]), $output[OutputLimits::MODULE_DATA_TOTALS]))
            );
        }
        if (!empty($output[OutputLimits::DATA_TRUNCATED])) {
            $notes[] = sprintf(
                'Long texts in data were cut to %s characters, and lists to %d entries. The rest can\'t be read through these tools.',
                number_format(OutputLimits::TEXT_LIMIT), OutputLimits::LIST_LIMIT
            );
        }
        $notes[] = self::textsNote($readable, array_keys(array_filter([
            'annotations' => $annotationTextsCut,
            'module_data' => !empty($output[OutputLimits::MODULE_DATA_TRUNCATED]),
        ])));
        if ($annotationListsCut) {
            $notes[] = self::annotationListNote();
        }
        $notes = array_filter($notes);
        return $notes ? implode(' ', $notes) : null;
    }

    /**
     * Say what the properties a search named cut in its results, and how to read
     * them in full.
     *
     * Each result marks its cuts: properties_totals for a property with more
     * values than it lists, and properties_truncated when a value was
     * shortened.
     *
     * @param array $summaries The page of results
     * @param string $resource "items", "media", or "item_sets"
     */
    public static function forSearchProperties(array $summaries, string $resource): ?string
    {
        $listsCut = array_filter(array_column($summaries, OutputLimits::PROPERTIES_TOTALS));
        $textsCut = array_filter(array_column($summaries, OutputLimits::PROPERTIES_TRUNCATED));
        if (!$listsCut && !$textsCut) {
            return null;
        }
        $cuts = [];
        if ($listsCut) {
            $cuts[] = sprintf('a property lists its first %d values (properties_totals gives the full count of each property cut)', OutputLimits::PROPERTY_VALUES_LIMIT);
        }
        if ($textsCut) {
            $cuts[] = sprintf('a value longer than %d characters is shortened, ending in "…"', OutputLimits::DESCRIPTION_LIMIT);
        }
        $readTool = ['items' => 'get_item', 'media' => 'get_media', 'item_sets' => 'get_item_set'][$resource];
        return sprintf('In "properties", %s. To read all of a property, call %s with "property".', implode(', and ', $cuts), $readTool);
    }

    /**
     * Say what the JSON-LD form cut, and how to reach the rest, as the
     * detailed format's note.
     *
     * @param array $cut The full length of each list cut, by name
     * @param int $textsCut How many long texts were cut
     */
    public static function forJsonLd(AbstractResourceEntityRepresentation $resource, array $cut, int $textsCut): ?string
    {
        $notes = [];
        if ($cut) {
            $notes[] = self::jsonLdListsNote($resource, $cut);
        }
        if ($textsCut) {
            $notes[] = sprintf(
                'Text was cut after its first %s characters, where it ends "%s". To read the rest of a property\'s value, call again with its "property", its "position" (from 1), and "offset" %d, without "detailed". Offsets count a value\'s readable text, which for HTML and other markup differs from its @value; read those from "offset" 0. Each call returns up to %s characters, and says where the next one starts. Text cut elsewhere, such as in an annotation, can\'t be read further through these tools.',
                number_format(OutputLimits::TEXT_LIMIT), sprintf(OutputLimits::CUT_MARKER, 'N'), OutputLimits::TEXT_LIMIT, number_format(OutputLimits::CHUNK_LIMIT)
            );
            if ($resource instanceof MediaRepresentation && 'html' === $resource->ingester()) {
                $notes[] = 'This HTML media\'s own text, in o-cnt:chars and data, reads in full with "content" set to true.';
            }
        }
        return $notes ? implode("\n", $notes) : null;
    }

    /**
     * Say what was cut in a page of one property's values.
     *
     * @param int $first The position of the page's first value, from 1
     */
    public static function forPropertyPage(string $term, array $values, int $first): ?string
    {
        [$positions, $inAnnotations] = self::findCutTexts($values, $first);
        $notes = [self::textsNote(
            array_map(fn ($position) => [$term, $position], $positions),
            $inAnnotations ? ['annotations'] : []
        )];
        if (self::hasCutAnnotationList($values)) {
            $notes[] = self::annotationListNote();
        }
        $notes = array_filter($notes);
        return $notes ? implode(' ', $notes) : null;
    }

    /**
     * Say which blocks of a site page had their text or data cut, and how to
     * read the text.
     *
     * The data marks each block cut with a truncated flag, for its text, and
     * a data_truncated flag, for its data.
     */
    public static function forSitePage(array $output): ?string
    {
        $positions = fn (string $mark) => array_column(array_filter(
            $output['blocks'] ?? [],
            fn ($block) => !empty($block[$mark])
        ), 'position');
        $plural = fn (array $cut) => count($cut) > 1 ? 's' : '';
        $notes = [];
        $cut = $positions(OutputLimits::TRUNCATED);
        if ($cut) {
            $notes[] = sprintf(
                'The text of block%s %s was cut. Call again with "block" set to its position to read it, %s characters at a time.',
                $plural($cut), implode(', ', $cut), number_format(OutputLimits::CHUNK_LIMIT)
            );
        }
        $cut = $positions(OutputLimits::DATA_TRUNCATED);
        if ($cut) {
            $notes[] = sprintf(
                'Long texts in the data of block%s %s were cut to %s characters, and lists to %d entries. The rest can\'t be read through these tools.',
                $plural($cut), implode(', ', $cut), number_format(OutputLimits::TEXT_LIMIT), OutputLimits::LIST_LIMIT
            );
        }
        return $notes ? implode(' ', $notes) : null;
    }

    /**
     * Say where a part of a text read from an offset ends, and the next
     * offset when there's more, or that the offset is past the end.
     *
     * @param array $chunk ['length', 'text']
     */
    public static function forChunk(array $chunk, int $offset): ?string
    {
        if ($offset && $offset >= $chunk['length']) {
            return sprintf('This text has %s characters, so "offset" %s is past its end.', number_format($chunk['length']), number_format($offset));
        }
        $end = $offset + mb_strlen($chunk['text']);
        if ($end >= $chunk['length']) {
            return null;
        }
        return sprintf(
            'These are characters %s to %s of %s. Call again with "offset" %d for the next.',
            number_format($offset), number_format($end), number_format($chunk['length']), $end
        );
    }

    /**
     * Say which lists the JSON-LD form cut, and how to reach the rest of each.
     *
     * @param array $cut The full length of each list cut, by name
     */
    private static function jsonLdListsNote(AbstractResourceEntityRepresentation $resource, array $cut): string
    {
        $names = array_keys($cut);
        $notes = [sprintf(
            'Lists longer than %d entries are cut to that many. Their full lengths: %s.',
            OutputLimits::LIST_LIMIT,
            implode(', ', array_map(fn ($name, $total) => sprintf('%s %d', $name, $total), $names, $cut))
        )];
        $terms = array_intersect($names, array_keys($resource->values()));
        if ($terms) {
            $notes[] = 'Call again with "property", and without "detailed", to read all of a property\'s values.';
        }
        if (isset($cut['o:media'])) {
            $notes[] = sprintf('search_media with "item" %d lists all the media.', $resource->id());
        }
        if (isset($cut['o:item_set'])) {
            $notes[] = 'The concise format lists all the item sets.';
        }
        $reverse = preg_grep('/^@reverse /', $names);
        if ($reverse) {
            // Items, media, and item sets can all link here.
            $notes[] = sprintf(
                'To find everything linking here, use a "links_to" condition on that property with the value %d: search_items finds items, search_media media, and search_item_sets item sets.',
                $resource->id()
            );
        }
        $rest = array_diff($names, $terms, $reverse, ['o:media', 'o:item_set']);
        if ($rest) {
            $notes[] = sprintf('The rest of %s can\'t be read through these tools.', implode(', ', $rest));
        }
        return implode("\n", $notes);
    }

    /**
     * Find which of a property's formatted values had their text cut.
     *
     * @param int $first The position of the first of $values, from 1
     * @return array [$positions, $inAnnotations]: the position of each value
     *   cut, and whether text was cut in their annotations
     */
    private static function findCutTexts(array $values, int $first): array
    {
        $positions = [];
        $inAnnotations = false;
        foreach (array_values($values) as $index => $value) {
            if (!empty($value[OutputLimits::TRUNCATED])) {
                $positions[] = $first + $index;
            }
            $inAnnotations = $inAnnotations || self::hasCutText($value['annotations'] ?? []);
        }
        return [$positions, $inAnnotations];
    }

    /**
     * Whether a list was cut in formatted values' annotations, at any depth.
     */
    private static function hasCutAnnotationList(array $values): bool
    {
        foreach ($values as $value) {
            foreach ($value['annotations'] ?? [] as $property) {
                if (isset($property[OutputLimits::TOTAL]) || self::hasCutAnnotationList($property['values'])) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function annotationListNote(): string
    {
        return sprintf(
            'Only the first %d values of a property in an annotation are shown, and the rest can\'t be read through these tools.',
            OutputLimits::LIST_LIMIT
        );
    }

    /**
     * Whether any text was cut in formatted values, or in their annotations.
     */
    private static function hasCutText(array $values): bool
    {
        foreach ($values as $property) {
            foreach ($property['values'] as $value) {
                if (!empty($value[OutputLimits::TRUNCATED]) || self::hasCutText($value['annotations'] ?? [])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Say which texts were cut, and how to read the rest of each.
     *
     * @param array $readable Each text an offset reads: a term and a value's
     *   position, or "content" and null
     * @param array $unreadable Where else text was cut, such as "annotations"
     */
    private static function textsNote(array $readable, array $unreadable): ?string
    {
        $notes = [];
        if ($readable) {
            $names = array_map(fn ($place) => $place[1] ? sprintf('%s value %d', $place[0], $place[1]) : 'the content', $readable);
            $last = array_pop($names);
            $list = $names ? sprintf('%s%s and %s', implode(', ', $names), count($names) > 1 ? ',' : '', $last) : $last;
            [$term, $position] = $readable[0];
            // A media's own text is read with "content", a property's with
            // "property".
            $example = json_encode(($position ? ['property' => $term, 'position' => $position] : ['content' => true]) + ['offset' => OutputLimits::TEXT_LIMIT]);
            $notes[] = sprintf(
                '%s %s cut after %s first %s characters.',
                // The content is named last, so it starts the sentence only
                // when it's alone. A term starting it keeps its case.
                $position ? $list : ucfirst($list),
                $names ? 'were' : 'was',
                $names ? 'their' : 'its',
                number_format(OutputLimits::TEXT_LIMIT)
            );
            $notes[] = $names
                ? sprintf(
                    'To read the rest of one, call again with %s "offset" %d, such as %s.',
                    array_filter(array_column($readable, 1)) ? 'its "property", its "position", and' : 'its "property" and',
                    OutputLimits::TEXT_LIMIT, $example
                )
                : sprintf('To read the rest, call again with %s.', $example);
            $notes[] = sprintf(
                'Each call returns up to %s characters, and says where the next one starts.',
                number_format(OutputLimits::CHUNK_LIMIT)
            );
        }
        if ($unreadable) {
            $notes[] = sprintf(
                'Text in %s was cut after its first %s characters, and the rest can\'t be read through these tools.',
                implode(' and ', $unreadable), number_format(OutputLimits::TEXT_LIMIT)
            );
        }
        return $notes ? implode(' ', $notes) : null;
    }
}
