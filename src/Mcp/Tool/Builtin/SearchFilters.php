<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Mcp\Query\NameLookup;
use McpServer\Mcp\Tool\ArgumentReader;

/**
 * Defines the filters and sorting the search tools share, and translates them
 * into Omeka's query for items, media, or item sets.
 *
 * Omeka's API search parameters are already a complete query language, which
 * modules extend through the api.search.query event. This only maps named,
 * documented arguments onto them, resolving terms, labels, and slugs so a
 * model never needs Omeka's internal IDs or query syntax. Every resource takes
 * the same arguments, except those FILTER_RESOURCES limits to some resources.
 * A filter added here must work for every resource's API adapter, or be listed
 * there.
 */
class SearchFilters
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

    // The dates search sorts by, as summaries name them, and the Omeka field
    // each sorts on.
    const DATE_SORTS = ['added' => 'created', 'modified' => 'modified'];

    const WHERE_MAX = 10;

    const ITEM_SETS_MAX = 100;

    // Most classes an error lists, so a model can correct a class name.
    const CLASSES_IN_ERROR_MAX = 50;

    protected $names;

    public function __construct(NameLookup $names)
    {
        $this->names = $names;
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
            'is_public' => [
                'type' => 'boolean',
                'description' => 'Only those that are public (true) or private (false). Private ones appear only to a caller signed in with an MCP key.',
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
                    $name, $this->noun(implode(' and ', $resources)), $this->noun($resource), $advice[$name] ?? ''
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
            $query['resource_class_id'] = $this->findClass($class);
        }
        if (null !== ($site = $reader->getText('site'))) {
            $query['site_id'] = $this->findSite($site);
        }
        if (null !== ($isPublic = $reader->getBool('is_public'))) {
            $query['is_public'] = $isPublic ? 1 : 0;
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
            case 'modified':
                return ['sort_by' => self::DATE_SORTS[$sort], 'sort_order' => $order ?? 'desc'];
            case 'title':
                return ['sort_by' => 'title', 'sort_order' => $order ?? 'asc'];
        }
        if (!str_contains($sort, ':')) {
            throw new ToolException('The "sort" argument must be "relevance", "added", "modified", "title", or a property term such as "dcterms:date".');
        }
        $this->findProperty($sort);
        return ['sort_by' => $sort, 'sort_order' => $order ?? 'asc'];
    }

    /**
     * Get the date a query from buildSort() sorts by, as summaries name it:
     * "added" or "modified", or null for any other sort.
     */
    public function sortedDate(array $query): ?string
    {
        return array_search($query['sort_by'] ?? null, self::DATE_SORTS, true) ?: null;
    }

    /**
     * Say why a query may have matched nothing, for a tool's note.
     *
     * @param string $noun What was searched, such as "item sets"
     */
    public function explainEmpty(array $query, string $noun): string
    {
        if (isset($query['fulltext_search'])) {
            // MySQL's full-text index skips stopwords and short words, so a
            // search for "the" finds nothing rather than everything.
            return sprintf(
                'No %s matched. Full-text search ignores very common words and words shorter than three letters, so if the query was made only of those, try more specific words, or "contains". Otherwise the installation may have nothing on this.',
                $noun
            );
        }
        // A condition on a property used only in annotations can't match,
        // since core searches the values of resources, not of annotations.
        $terms = $this->names->getAnnotationOnlyTerms(array_column($query['property'] ?? [], 'property'));
        if ($terms) {
            return sprintf(
                'No %s matched. %s %s used only in value annotations, which "where" can\'t search. count_values on %s names the fields whose values carry those annotations, and get_item with "field" set to one of them shows each value with its annotations.',
                $noun, implode(' and ', $terms), count($terms) > 1 ? 'are' : 'is', $terms[0]
            );
        }
        if (isset($query['is_public']) && !$query['is_public']) {
            return sprintf(
                'No %1$s matched. "is_public" false finds only the private %1$s you may see, which is none without an MCP key, so there may be others.',
                $noun
            );
        }
        return sprintf('No %s matched. Loosen the filters, or check them against get_overview.', $noun);
    }

    /**
     * Get what a resource is called in messages, such as "item sets".
     */
    public function noun(string $resource): string
    {
        return str_replace('_', ' ', $resource);
    }

    /**
     * Find the class a term or label names, among the classes in use.
     *
     * @throws ToolException When nothing matches, or a label matches several
     */
    public function findClass(string $termOrLabel): int
    {
        $matches = str_contains($termOrLabel, ':')
            ? array_filter([$this->names->findUsedTerm('resource_classes', $termOrLabel)])
            : $this->names->findUsedClassesByLabel($termOrLabel);
        if (1 === count($matches)) {
            return reset($matches)['id'];
        }
        if (!$matches) {
            // get_overview lists only the classes items use, so a class used
            // only by media or item sets is named here.
            $classes = $this->names->getUsedClasses();
            if (count($classes) <= self::CLASSES_IN_ERROR_MAX) {
                throw new ToolException(sprintf(
                    'Nothing uses the class "%s". The classes in use are: %s.',
                    $termOrLabel,
                    implode(', ', array_map(fn ($class) => sprintf('%s (%s)', $class['term'], $class['label']), $classes))
                ));
            }
            throw new ToolException(sprintf('Nothing uses the class "%s". Call get_overview to see the classes items use.', $termOrLabel));
        }
        throw new ToolException(sprintf(
            'The label "%s" matches several classes. Use one of their terms instead: %s.',
            $termOrLabel, implode(', ', array_column($matches, 'term'))
        ));
    }

    /**
     * Find the property a term names, among the properties in use.
     *
     * @throws ToolException When no property in use has the term
     */
    public function findProperty(string $term): int
    {
        $property = $this->names->findUsedTerm('properties', $term);
        if (!$property) {
            throw new ToolException(sprintf('No values use the property "%s". Call get_overview to see the properties in use.', $term));
        }
        return $property['id'];
    }

    /**
     * Find the site a slug names.
     *
     * @throws ToolException When no visible site has the slug
     */
    public function findSite(string $slug): int
    {
        $siteId = array_search($slug, $this->names->getSiteSlugs(), true);
        if (false === $siteId) {
            throw new ToolException(sprintf('There is no site "%s". Call get_overview to see the sites.', $slug));
        }
        return (int) $siteId;
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
        $row = ['joiner' => 'and', 'property' => $this->findProperty($field), 'type' => self::OPERATORS[$op]];
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
