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
 * same arguments, except those FILTER_RESOURCES limits to some resources. A
 * filter added here must work for every resource's API adapter, or be listed
 * there.
 */
class ResourceQueryBuilder
{
    // The resources the tools search and count, by API name.
    const RESOURCES = ['items', 'media', 'item_sets'];

    // Filters only some resources take, and which. Core's media search has no
    // item set or media filter, since media belong to item sets only through
    // their item, and only media have a media type.
    const FILTER_RESOURCES = [
        'item_set' => ['items'],
        'has_media' => ['items'],
        'media_type' => ['media'],
    ];

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
     * @param string|null $resource "items", "media", or "item_sets", or null
     *   for every filter, for a tool that searches more than one
     */
    public function getFilterSchema(?string $resource): array
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
                'description' => 'Only those of this class, by term (e.g. "dctype:Text") or label (e.g. "Text").',
            ],
            'site' => [
                'type' => 'string',
                'description' => 'Only those in this site, by slug.',
            ],
            'has_media' => [
                'type' => 'boolean',
                'description' => 'Only items with media (true) or without (false).',
            ],
            'media_type' => [
                'type' => 'string',
                'description' => 'Only media of this exact type, e.g. "application/pdf" or "image/jpeg". get_overview lists the ones in use.',
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
                            'description' => '"links_to" takes the id of an item, media, or item set; "exists" and "missing" take no value.',
                        ],
                        'value' => ['type' => ['string', 'integer']],
                    ],
                    'required' => ['field', 'op'],
                ],
            ],
        ];
        if (null === $resource) {
            return $schema;
        }
        return array_filter(
            $schema,
            fn ($name) => in_array($resource, self::FILTER_RESOURCES[$name] ?? [$resource], true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Build the query for the filter arguments.
     *
     * A blank argument counts as absent. A filter the resource doesn't take is
     * refused, not ignored, so a model never reads unfiltered results as
     * filtered ones.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @param array $advice What to do instead, by filter name, added to a
     *   refusal
     * @throws ToolException For an argument the model should correct
     */
    public function buildFilters(ArgumentReader $reader, string $resource, array $advice = []): array
    {
        foreach (self::FILTER_RESOURCES as $name => $resources) {
            if (!in_array($resource, $resources, true) && $reader->has($name)) {
                throw new ToolException(trim(sprintf(
                    '"%s" filters only %s, not %s. %s',
                    $name, $this->label(implode(' and ', $resources)), $this->label($resource), $advice[$name] ?? ''
                )));
            }
        }
        $query = [];
        if (null !== ($text = $reader->getText('query'))) {
            $query['fulltext_search'] = $text;
        }
        if (null !== ($contains = $reader->getText('contains'))) {
            // Matches any value, URI, or linked resource's title as a substring.
            $query['search'] = $this->refuseZero($contains);
        }
        if ($itemSets = $reader->getIntList('item_set', 1, self::ITEM_SETS_MAX)) {
            $query['item_set_id'] = $itemSets;
        }
        if (null !== ($class = $reader->getText('class'))) {
            $query['resource_class_id'] = $this->options->findClass($class);
        }
        if (null !== ($site = $reader->getText('site'))) {
            $query['site_id'] = $this->options->findSite($site);
        }
        if (null !== ($hasMedia = $reader->getBool('has_media'))) {
            $query['has_media'] = $hasMedia ? 1 : 0;
        }
        if (null !== ($mediaType = $reader->getText('media_type'))) {
            $query['media_type'] = $this->refuseZero($mediaType);
        }
        foreach ($reader->getList('where', self::WHERE_MAX) as $index => $condition) {
            $query['property'][] = $this->buildCondition($condition, $index);
        }
        return $query;
    }

    /**
     * Translate "sort" and "order" into Omeka's sort parameters.
     *
     * Relevance, the default for a full-text query, uses core's default-sort
     * keys. Core's full-text search reads sort_order without checking it's set,
     * so it is always set.
     *
     * @param array|null $default ['sort' => ..., 'order' => ...] to use when
     *   neither a sort nor a query is given, in place of "added"
     */
    public function buildSort(ArgumentReader $reader, bool $hasQuery, ?array $default = null): array
    {
        $sort = $reader->getText('sort');
        $order = $reader->getEnum('order', ['asc', 'desc']);
        if (null === $sort) {
            $sort = $hasQuery ? 'relevance' : ($default['sort'] ?? 'added');
            if (!$hasQuery) {
                $order ??= $default['order'] ?? null;
            }
        }
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

    /**
     * Say why a query may have matched nothing, for a tool's note.
     *
     * @param string $label What was searched, such as "item sets"
     */
    public function explainEmpty(array $query, string $label): string
    {
        if (isset($query['fulltext_search'])) {
            // MySQL's full-text index skips stopwords and short words, so a
            // search for "the" finds nothing rather than everything.
            return sprintf(
                'No %s matched. Full-text search ignores very common words and words shorter than three letters, so if the query was made only of those, try more specific words, or "contains". Otherwise the collection may have nothing on this.',
                $label
            );
        }
        // A condition on a property used only in annotations can't match,
        // since core searches the values of resources, not of annotations.
        $terms = $this->options->getAnnotationOnlyTerms(array_column($query['property'] ?? [], 'property'));
        if ($terms) {
            return sprintf(
                'No %s matched. %s %s used only in value annotations, which "where" can\'t search. count_values on %s names the fields whose values carry those annotations, and get_item with "field" set to one of them shows each value with its annotations.',
                $label, implode(' and ', $terms), count($terms) > 1 ? 'are' : 'is', $terms[0]
            );
        }
        return sprintf('No %s matched. Loosen the filters, or check them against get_overview.', $label);
    }

    /**
     * Get what a resource is called in messages, such as "item sets".
     */
    public function label(string $resource): string
    {
        return str_replace('_', ' ', $resource);
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
        $field = $reader->getText('field');
        $op = $reader->getEnum('op', array_keys(self::OPERATORS));
        if (null === $field || null === $op) {
            throw new ToolException('Each condition needs both "field" (a property term) and "op".');
        }
        $row = ['joiner' => 'and', 'property' => $this->options->findProperty($field), 'type' => self::OPERATORS[$op]];
        if (in_array($op, ['exists', 'missing'], true)) {
            return $row;
        }
        if ('links_to' === $op) {
            $row['text'] = (string) $reader->requireInt('value', 1);
            return $row;
        }
        $value = $condition['value'] ?? null;
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ('' === $text) {
            throw new ToolException(sprintf('"value" is needed for the "%s" operator.', $op));
        }
        $row['text'] = $this->refuseZero($text);
        return $row;
    }

    /**
     * Refuse a filter value of "0", which core skips as if it were empty, so
     * the search would match everything.
     *
     * @throws ToolException
     */
    protected function refuseZero(string $text): string
    {
        if ('0' === $text) {
            throw new ToolException('Searching for the value "0" isn\'t supported: Omeka\'s search treats it as empty.');
        }
        return $text;
    }
}
