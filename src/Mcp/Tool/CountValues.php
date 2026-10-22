<?php
namespace McpServer\Mcp\Tool;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Stdlib\ResourceCounter;
use McpServer\Stdlib\ResourceFormatter;
use McpServer\Stdlib\ResourceQueryBuilder;
use McpServer\Stdlib\SearchOptions;

/**
 * Counts the values of one field across the items, media, or item sets that
 * match the filters.
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

    protected $queryBuilder;

    protected $options;

    protected $counter;

    protected $formatter;

    public function __construct(ResourceQueryBuilder $queryBuilder, SearchOptions $options, ResourceCounter $counter, ResourceFormatter $formatter)
    {
        $this->queryBuilder = $queryBuilder;
        $this->options = $options;
        $this->counter = $counter;
        $this->formatter = $formatter;
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
            . 'side by side.';
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
                'sort' => [
                    'type' => 'string',
                    'enum' => self::SORTS,
                    'default' => 'count',
                    'description' => '"count" for the most common values first, or "value" for alphabetical order.',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => AbstractSearchTool::PAGE_MAX,
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
                    'enum' => ResourceQueryBuilder::RESOURCES,
                    'default' => 'items',
                    'description' => 'Whose values to count: items, media, or item sets.',
                ],
            ] + $this->queryBuilder->getFilterSchema(null),
            'required' => ['field'],
        ];
    }

    public function getAnnotations(): array
    {
        return Annotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $reader = new ArgumentReader($arguments);
        $field = $reader->getText('field');
        if (null === $field) {
            throw new ToolException('"field" is needed: a property term such as "dcterms:subject".');
        }
        $propertyId = $this->options->findProperty($field);
        $page = $reader->getInt('page', 1, 1, AbstractSearchTool::PAGE_MAX);
        $perPage = $reader->getInt('per_page', self::PER_PAGE_DEFAULT, 1, self::PER_PAGE_MAX);
        $byValue = 'value' === $reader->getEnum('sort', self::SORTS, 'count');
        $resource = $reader->getEnum('resource_type', ResourceQueryBuilder::RESOURCES, 'items');
        // Named for the resource, such as "item sets", in messages.
        $label = $this->queryBuilder->label($resource);
        $filters = $this->queryBuilder->buildFilters(
            $reader,
            $resource,
            array_fill_keys(array_keys(ResourceQueryBuilder::RESOURCE_FILTERS), '"resource_type" chooses what to count.')
        );
        $ids = $this->counter->getResourceIds($resource, $filters);
        if (null === $ids) {
            throw new ToolException(sprintf(
                'More than %s %s match, too many to count at once. Narrow them with filters, such as a class or a condition on a field.',
                number_format(ResourceCounter::COUNT_MAX), $label
            ));
        }
        $values = [];
        $totals = ['resources' => 0, 'values' => 0];
        $rows = $ids ? $this->counter->countValues($propertyId, $ids, $perPage, ($page - 1) * $perPage, $byValue) : [];
        foreach ($rows as $row) {
            $values[] = [
                // A linked resource with no title shows as get_item shows it.
                'value' => '' === $row['label'] ? '[Untitled]' : $this->formatter->shorten($row['label'], self::VALUE_LIMIT),
                $resource => (int) $row['resources'],
            ];
        }
        if ($rows || ($ids && $page > 1)) {
            // With no rows on the first page, both totals are 0. A later page
            // can be empty while there are values.
            $totals = $this->counter->countValueTotals($propertyId, $ids);
        }
        $output = [
            'field' => $field,
            'matching_' . $resource => count($ids),
            $resource . '_with_field' => $totals['resources'],
            'distinct_values' => $totals['values'],
            'page' => $page,
            'per_page' => $perPage,
            'values' => $values,
        ];
        $note = null;
        if (!$ids) {
            $note = $this->queryBuilder->explainEmpty($filters, $label);
        } elseif (!$totals['resources']) {
            $note = sprintf('None of the matching %s has a value for %s.', $label, $field);
            if ('items' === $resource) {
                $note .= ' Media and item sets have values of their own: set "resource_type" to count theirs.';
            }
        }
        return ToolResult::json($output, $note);
    }
}
