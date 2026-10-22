<?php
namespace McpServer\Stdlib;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Mcp\Tool\ArgumentReader;

/**
 * Translates search arguments into Omeka's query for items, media, or item sets.
 *
 * Omeka's API search parameters are already a complete query language, which
 * modules extend through the api.search.query event. This only maps named,
 * documented arguments onto them, resolving terms, labels, and slugs so a model
 * never needs Omeka's internal IDs or query syntax. Every resource takes the
 * same arguments, except the filters only items have. A filter added here
 * must work for every resource's API adapter, or be listed as item-only.
 */
class ResourceQueryBuilder
{
    // Filters that only item searches take. Core's media search has no
    // equivalent: media belong to item sets only through their item.
    const ITEM_FILTERS = ['item_set', 'has_media'];

    // Conditions in "where", and the Omeka property query types they use.
    const OPERATORS = [
        'equals' => 'eq',
        'contains' => 'in',
        'starts_with' => 'sw',
        'ends_with' => 'ew',
        'exists' => 'ex',
        'missing' => 'nex',
        'links_to' => 'res',
    ];

    const WHERE_MAX = 10;

    const ITEM_SETS_MAX = 100;

    // Core's property query skips a value of "0" as if it were empty, which
    // would match everything, so the tools refuse it.
    const ZERO = 'Searching for the value "0" isn\'t supported: Omeka\'s search treats it as empty.';

    protected $options;

    public function __construct(SearchOptions $options)
    {
        $this->options = $options;
    }

    /**
     * Get the JSON Schema properties of the filter arguments.
     *
     * Every tool that filters takes these, so they behave the same everywhere,
     * and a filter added here reaches every tool.
     *
     * @param string $resource "items", "media", or "item_sets"
     */
    public function getFilterSchema(string $resource): array
    {
        $schema = [
            'query' => [
                'type' => 'string',
                'description' => 'Words to search for, ranked by relevance. Matches whole words only.',
            ],
            'contains' => [
                'type' => 'string',
                'description' => 'Text that must appear somewhere in the values, even inside a word. Unranked.',
            ],
            'item_set' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'maxItems' => self::ITEM_SETS_MAX,
                'description' => 'Only items in any of these item sets, by id.',
            ],
            'class' => [
                'type' => 'string',
                'description' => 'Only those of this class, by term (e.g. "dcterms:Text") or label (e.g. "Text").',
            ],
            'site' => [
                'type' => 'string',
                'description' => 'Only those in this site, by slug.',
            ],
            'has_media' => [
                'type' => 'boolean',
                'description' => 'Only items with media (true) or without (false).',
            ],
            'where' => [
                'type' => 'array',
                'maxItems' => self::WHERE_MAX,
                'description' => 'Conditions on fields, all of which must hold. Dates are usually stored starting '
                    . 'with the year, such as "1776-08-22", so "starts_with" "17" finds the 1700s.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'field' => ['type' => 'string', 'description' => 'A property term, e.g. "dcterms:creator".'],
                        'op' => [
                            'type' => 'string',
                            'enum' => array_keys(self::OPERATORS),
                            'description' => '"links_to" takes an item id; "exists" and "missing" take no value.',
                        ],
                        'value' => ['type' => ['string', 'integer']],
                    ],
                    'required' => ['field', 'op'],
                ],
            ],
        ];
        return 'items' === $resource ? $schema : array_diff_key($schema, array_flip(self::ITEM_FILTERS));
    }

    /**
     * Build the query for search arguments, without paging.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @throws ToolException For an argument the model should correct
     */
    public function build(ArgumentReader $reader, string $resource): array
    {
        $query = $this->buildFilters($reader, $resource);
        return $query + $this->buildSort($reader, isset($query['fulltext_search']));
    }

    /**
     * Build the query for the filter arguments alone.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @throws ToolException For an argument the model should correct
     */
    public function buildFilters(ArgumentReader $reader, string $resource): array
    {
        $query = [];
        $text = trim((string) $reader->getString('query'));
        if ('' !== $text) {
            $query['fulltext_search'] = $text;
        }
        $contains = trim((string) $reader->getString('contains'));
        if ('0' === $contains) {
            throw new ToolException(self::ZERO);
        }
        if ('' !== $contains) {
            // Matches any value, URI, or linked resource's title as a substring.
            $query['search'] = $contains;
        }
        if ('items' === $resource && ($itemSets = $reader->getIntList('item_set', 1, self::ITEM_SETS_MAX))) {
            $query['item_set_id'] = $itemSets;
        }
        if (null !== ($class = $reader->getString('class'))) {
            $query['resource_class_id'] = $this->options->findClass(trim($class));
        }
        if (null !== ($site = $reader->getString('site'))) {
            $query['site_id'] = $this->options->findSite(trim($site));
        }
        if ('items' === $resource && null !== ($hasMedia = $reader->getBool('has_media'))) {
            $query['has_media'] = $hasMedia ? 1 : 0;
        }
        foreach ($reader->getList('where', self::WHERE_MAX) as $index => $condition) {
            $query['property'][] = $this->buildCondition($condition, $index);
        }
        return $query;
    }

    /**
     * Translate one "where" condition into an Omeka property query row.
     */
    protected function buildCondition($condition, int $index): array
    {
        try {
            return $this->translateCondition($condition);
        } catch (ToolException $e) {
            // Name the condition, since there may be up to ten.
            throw new ToolException(sprintf('where[%d]: %s', $index, $e->getMessage()));
        }
    }

    protected function translateCondition($condition): array
    {
        if (!is_array($condition) || array_is_list($condition)) {
            throw new ToolException('Each condition must be an object with "field", "op", and usually "value".');
        }
        $reader = new ArgumentReader($condition);
        $field = $reader->getString('field');
        $op = $reader->getEnum('op', array_keys(self::OPERATORS));
        if (null === $field || null === $op) {
            throw new ToolException('Each condition needs both "field" (a property term) and "op".');
        }
        $row = ['joiner' => 'and', 'property' => $this->options->findProperty(trim($field)), 'type' => self::OPERATORS[$op]];
        if (in_array($op, ['exists', 'missing'], true)) {
            return $row;
        }
        if ('links_to' === $op) {
            $row['text'] = (string) $reader->requireInt('value', 1);
            return $row;
        }
        $value = $condition['value'] ?? null;
        if (!is_scalar($value) || '' === trim((string) $value)) {
            throw new ToolException(sprintf('"value" is needed for the "%s" operator.', $op));
        }
        if ('0' === trim((string) $value)) {
            throw new ToolException(self::ZERO);
        }
        $row['text'] = trim((string) $value);
        return $row;
    }

    /**
     * Translate "sort" and "order" into Omeka's sort parameters.
     *
     * Relevance, the default for a full-text query, uses core's default-sort
     * keys. Core's full-text search reads sort_order without checking it's set,
     * so it is always set.
     */
    protected function buildSort(ArgumentReader $reader, bool $hasQuery): array
    {
        $sort = trim((string) $reader->getString('sort')) ?: ($hasQuery ? 'relevance' : 'added');
        $order = $reader->getEnum('order', ['asc', 'desc']);
        switch ($sort) {
            case 'relevance':
                if (!$hasQuery) {
                    throw new ToolException('Sorting by relevance needs a "query".');
                }
                if ('asc' === $order) {
                    throw new ToolException('Relevance always lists the most relevant first. Leave "order" out, or sort by something else.');
                }
                return ['sort_by_default' => '', 'sort_order_default' => '', 'sort_order' => 'desc'];
            case 'added':
                return ['sort_by' => 'created', 'sort_order' => $order ?? 'desc'];
            case 'modified':
                return ['sort_by' => 'modified', 'sort_order' => $order ?? 'desc'];
            case 'title':
                return ['sort_by' => 'title', 'sort_order' => $order ?? 'asc'];
        }
        if (!str_contains($sort, ':')) {
            throw new ToolException('The "sort" argument must be "relevance", "added", "modified", "title", or a property term such as "dcterms:date".');
        }
        $this->options->findProperty($sort);
        return ['sort_by' => $sort, 'sort_order' => $order ?? 'asc'];
    }
}
