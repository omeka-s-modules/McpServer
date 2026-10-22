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
 * grouped query reads only the values of those resources. Core's Doctrine filters then hide
 * private values, and the titles of linked resources the caller can't see. A
 * linked media counts by its title even when its item is private, as core's
 * own pages show it.
 *
 * Values group under the database's collation, so values that differ only in
 * case, accents, or trailing spaces count as one. MySQL also compares only the
 * first max_sort_length bytes (1,024 by default) of long text, so very long
 * values that start alike may count as one.
 */
class CountValues implements ToolInterface
{
    const LIMIT_DEFAULT = 20;

    const LIMIT_MAX = 100;

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
            . '"media_type", such as "image/jpeg". Item sets and media presence filter only items.';
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
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::LIMIT_MAX,
                    'default' => self::LIMIT_DEFAULT,
                    'description' => sprintf('How many of the most common values to return, from 1 to %d.', self::LIMIT_MAX),
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
        $limit = $reader->getInt('limit', self::LIMIT_DEFAULT, 1, self::LIMIT_MAX);
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
        $rows = $ids ? $this->counter->countValues($propertyId, $ids, $limit) : [];
        foreach ($rows as $row) {
            $values[] = [
                // A linked resource with no title shows as get_item shows it.
                'value' => '' === $row['label'] ? '[Untitled]' : $this->formatter->shorten($row['label'], self::VALUE_LIMIT),
                $resource => (int) $row['resources'],
            ];
        }
        if ($rows) {
            // With no rows, both totals are 0.
            $totals = $this->counter->countValueTotals($propertyId, $ids);
        }
        $output = [
            'field' => $field,
            'matching_' . $resource => count($ids),
            $resource . '_with_field' => $totals['resources'],
            'distinct_values' => $totals['values'],
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
