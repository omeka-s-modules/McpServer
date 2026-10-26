<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Mcp\Format\OutputLimits;
use McpServer\Mcp\Query\ResourceCounter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;

/**
 * Counts the values of one field across the items, media, or item sets that
 * match the filters. With prefix_length, values count by their first
 * characters instead, such as dates by century.
 *
 * Omeka's API can't group, so the counting is ResourceCounter's, under the
 * rule in ToolInterface: the API returns the IDs of the matching resources,
 * applying every filter, module extension, and visibility rule, and the
 * grouped query reads only the values of those resources. Core's Doctrine
 * filters then hide private values, and the titles of linked resources the
 * caller can't see. A linked media counts by its title even when its item is
 * private, as core's own pages show it.
 *
 * Values group under the database's collation, so values that differ only in
 * case, accents, or trailing spaces count as one. MySQL also compares only the
 * first max_sort_length bytes (1,024 by default) of long text, so very long
 * values that start alike may count as one.
 */
class CountValues implements ToolInterface
{
    const PER_PAGE_DEFAULT = 20;

    const PER_PAGE_MAX = 100;

    const SORTS = ['count', 'value'];

    // Longest value shown, since a field may hold long text.
    const VALUE_LIMIT = 300;

    // Most characters "prefix_length" counts values by.
    const PREFIX_LENGTH_MAX = 50;

    protected $searchFilters;

    protected $counter;

    public function __construct(SearchFilters $searchFilters, ResourceCounter $counter)
    {
        $this->searchFilters = $searchFilters;
        $this->counter = $counter;
    }

    public function getTitle(): string
    {
        return 'Count values';
    }

    public function getDescription(): string
    {
        return 'Count the values of one field: which values are most common, and how many items have each. Use '
            . 'it for questions such as the most frequent creators, or the most common subjects among paintings, '
            . 'instead of paging through search results. Takes the same filters as search_items, to count within '
            . 'a subset. A value that links to another item counts by that item\'s title. Call get_overview first '
            . 'to see the properties in use.'
            . "\n\n"
            . 'Counts items by default. Media and item sets have metadata of their own, such as the rights and '
            . 'credits of each image: set "resource_type" to count theirs. Counting media also takes '
            . '"media_type", such as "image/jpeg". Item sets and media presence filter only items.'
            . "\n\n"
            . 'Returns a page of values; distinct_values says how many there are, and "page" reaches the rest. '
            . 'Sort by "value" to list them alphabetically, which puts variant spellings of the same value '
            . 'side by side. To see how dates spread, count them by century or decade with "prefix_length".';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'field' => [
                    'type' => 'string',
                    'description' => 'The property to count, by term, e.g. "dcterms:subject".',
                ],
                'prefix_length' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::PREFIX_LENGTH_MAX,
                    'description' => 'Count values by their first this many characters, rather than whole. Dates are usually stored starting with the year, so 2 counts them by century ("17" for the 1700s) and 3 by decade. A group\'s text works as a "starts_with" condition in a search, to list its records.',
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => self::SORTS,
                    'default' => 'count',
                    'description' => '"count" for the most common values first, or "value" for alphabetical order.',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => OutputLimits::PAGE_MAX,
                    'default' => 1,
                    'description' => 'Page of values to return, starting at 1.',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::PER_PAGE_MAX,
                    'default' => self::PER_PAGE_DEFAULT,
                    'description' => sprintf('Values per page, from 1 to %d.', self::PER_PAGE_MAX),
                ],
                'resource_type' => [
                    'type' => 'string',
                    'enum' => SearchFilters::RESOURCES,
                    'default' => 'items',
                    'description' => 'Whose values to count: items, media, or item sets.',
                ],
            ] + $this->searchFilters->getFilterSchema(null),
            'required' => ['field'],
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $reader = new ArgumentReader($arguments);
        $field = $reader->getText('field');
        if (null === $field) {
            throw new ToolException('"field" is needed: a property term such as "dcterms:subject".');
        }
        $propertyId = $this->searchFilters->findProperty($field);
        $page = $reader->getInt('page', 1, 1, OutputLimits::PAGE_MAX);
        $perPage = $reader->getInt('per_page', self::PER_PAGE_DEFAULT, 1, self::PER_PAGE_MAX);
        $byValue = 'value' === $reader->getEnum('sort', self::SORTS, 'count');
        $prefixLength = $reader->getInt('prefix_length', null, 1, self::PREFIX_LENGTH_MAX);
        $resource = $reader->getEnum('resource_type', SearchFilters::RESOURCES, 'items');
        // What the resources are called in messages, such as "item sets".
        $noun = $this->searchFilters->noun($resource);
        $filters = $this->searchFilters->buildFilters(
            $reader,
            $resource,
            array_fill_keys(array_keys(SearchFilters::FILTER_RESOURCES), '"resource_type" chooses what to count.')
        );
        $ids = $this->counter->getResourceIds($resource, $filters);
        if (null === $ids) {
            throw new ToolException(sprintf(
                'More than %s %s match, too many to count at once. Narrow them with filters, such as a class or a condition on a field.',
                number_format(ResourceCounter::COUNT_MAX), $noun
            ));
        }
        $values = [];
        $shortened = false;
        $totals = ['resources' => 0, 'values' => 0];
        $rows = $ids ? $this->counter->countValues($propertyId, $ids, $perPage, ($page - 1) * $perPage, $byValue, $prefixLength) : [];
        foreach ($rows as $row) {
            $value = [
                // A linked resource with no title shows as "[Untitled]", as get_item
                // shows it.
                'value' => '' === $row['text'] ? '[Untitled]' : OutputLimits::shorten($row['text'], self::VALUE_LIMIT),
                $resource => (int) $row['resources'],
            ];
            if (mb_strlen(trim($row['text'])) > self::VALUE_LIMIT) {
                $value[OutputLimits::TRUNCATED] = true;
                $shortened = true;
            }
            $values[] = $value;
        }
        if ($rows || ($ids && $page > 1)) {
            // With no rows on the first page, both totals are 0. A later page
            // can be empty while there are values.
            $totals = $this->counter->countValueTotals($propertyId, $ids, $prefixLength);
        }
        $output = ['field' => $field];
        if ($prefixLength) {
            // The values are the groups' first characters.
            $output['prefix_length'] = $prefixLength;
        }
        $output += [
            'matching_' . $resource => count($ids),
            $resource . '_with_field' => $totals['resources'],
            'distinct_values' => $totals['values'],
            'page' => $page,
            'per_page' => $perPage,
            'values' => $values,
        ];
        $note = null;
        if (!$ids) {
            $note = $this->searchFilters->explainEmpty($filters, $noun);
        } elseif (!$totals['resources']) {
            $annotated = $this->counter->getAnnotatedProperties($propertyId, $ids);
            if ($annotated) {
                $note = sprintf(
                    'None of the matching %s has a value for %s, but it\'s used in annotations on their %s values. Annotations can\'t be searched or counted with these tools. get_item with "field" set to %s shows each value with its annotations.',
                    $noun, $field, implode(' and ', $annotated), 1 === count($annotated) ? $annotated[0] : 'one of those'
                );
            } else {
                $note = sprintf('None of the matching %s has a value for %s.', $noun, $field);
                if ('items' === $resource) {
                    $note .= ' Media and item sets have values of their own: set "resource_type" to count theirs.';
                }
            }
        } elseif ($shortened) {
            $note = sprintf(
                'Values longer than %d characters are shortened, ending in "…", and marked truncated. To find the %s with one, use search_%s with a "starts_with" condition on %s, giving its text without the "…".',
                self::VALUE_LIMIT, $noun, $resource, $field
            );
        }
        return ToolResult::json($output, $note);
    }
}
