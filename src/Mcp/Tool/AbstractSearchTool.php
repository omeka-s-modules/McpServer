<?php
namespace McpServer\Mcp\Tool;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Stdlib\ItemFormatter;
use McpServer\Stdlib\ResourceQueryBuilder;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * What the search tools share, so they take the same arguments and answer
 * the same way where they overlap.
 */
abstract class AbstractSearchTool implements ToolInterface
{
    const PER_PAGE_MAX = 50;

    // Keeps offsets well within range: 500,000 results at the largest page size.
    const PAGE_MAX = 10000;

    protected $api;

    protected $formatter;

    protected $queryBuilder;

    public function __construct(ApiManager $api, ItemFormatter $formatter, ResourceQueryBuilder $queryBuilder)
    {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * Get the API resource searched: "items", "media", or "item_sets".
     */
    abstract protected function getResourceName(): string;

    abstract protected function getPerPageDefault(): int;

    /**
     * Get the short form of one result.
     */
    abstract protected function summarize(AbstractResourceEntityRepresentation $resource): array;

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => $this->getSearchSchema()];
    }

    public function getAnnotations(): array
    {
        return Annotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $this->refuseItemFilters($arguments);
        return ToolResult::json($this->search(new ArgumentReader($arguments)));
    }

    /**
     * Get what the results are called in messages, such as "item sets".
     */
    protected function getLabel(): string
    {
        return str_replace('_', ' ', $this->getResourceName());
    }

    /**
     * Get how to do what an item-only filter would have done, for the error
     * when another search is given one.
     */
    protected function getItemFilterAdvice(): string
    {
        return 'Find the items with search_items.';
    }

    /**
     * Refuse the filters only items have, rather than ignoring them.
     *
     * @throws ToolException
     */
    protected function refuseItemFilters(array $arguments): void
    {
        if ('items' === $this->getResourceName()) {
            return;
        }
        foreach (ResourceQueryBuilder::ITEM_FILTERS as $name) {
            if (array_key_exists($name, $arguments)) {
                throw new ToolException(sprintf(
                    'Searching %s doesn\'t take "%s", which only items have. %s',
                    $this->getLabel(), $name, $this->getItemFilterAdvice()
                ));
            }
        }
    }

    /**
     * Get the schema properties every search takes: filters, sorting, and paging.
     */
    protected function getSearchSchema(): array
    {
        return $this->queryBuilder->getFilterSchema($this->getResourceName()) + [
            'sort' => [
                'type' => 'string',
                'description' => '"relevance" (the default with a query), "added" (the default otherwise: when it was '
                    . 'added), "modified" (when it was last changed), "title", or a property term such as '
                    . '"dcterms:date". Sorting by "added" or "modified" also gives each result that date.',
            ],
            'order' => [
                'type' => 'string',
                'enum' => ['asc', 'desc'],
                'description' => 'Sort direction. Defaults to desc for "added" and "modified" and asc otherwise. Relevance is always most relevant first.',
            ],
            'page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => self::PAGE_MAX,
                'default' => 1,
                'description' => 'Page of results to return, starting at 1.',
            ],
            'per_page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => self::PER_PAGE_MAX,
                'default' => $this->getPerPageDefault(),
                'description' => sprintf('Results per page, from 1 to %d.', self::PER_PAGE_MAX),
            ],
        ];
    }

    /**
     * Run the search and summarize a page of results, with any hints.
     *
     * @param array $query Query parameters the tool sets itself, such as a
     *   media type
     */
    protected function search(ArgumentReader $reader, array $query = []): array
    {
        $resourceName = $this->getResourceName();
        $page = $reader->getInt('page', 1, 1, self::PAGE_MAX);
        $perPage = $reader->getInt('per_page', $this->getPerPageDefault(), 1, self::PER_PAGE_MAX);
        $query = $this->queryBuilder->build($reader, $resourceName) + $query + ['page' => $page, 'per_page' => $perPage];
        $response = $this->api->search($resourceName, $query);
        $results = $response->getContent();
        $output = [
            'total_results' => $response->getTotalResults(),
            'page' => $page,
            'per_page' => $perPage,
            $resourceName => array_map(
                fn ($resource) => $this->summarize($resource) + $this->formatter->sortDate($resource, $query),
                $results
            ),
        ];
        $hint = 0 === $output['total_results']
            ? $this->getEmptyHint($query)
            : $this->getSortHint($query, $results, $output['total_results']);
        if (null !== $hint) {
            $output['hint'] = $hint;
        }
        return $output;
    }

    /**
     * Say why a search may have found nothing.
     */
    protected function getEmptyHint(array $query): string
    {
        if (isset($query['fulltext_search'])) {
            // MySQL's full-text index skips stopwords and short words, so a
            // search for "the" finds nothing rather than everything.
            return sprintf(
                'No %s matched. Full-text search ignores very common words and words shorter than three letters, so if the query was made only of those, try more specific words, or "contains". Otherwise the collection may have nothing on this.',
                $this->getLabel()
            );
        }
        return sprintf('No %s matched. Loosen the filters, or check them against get_overview.', $this->getLabel());
    }

    /**
     * Say when sorting by a property doesn't order the results as it seems to.
     *
     * Core sorts on a property's stored text, and silently. Results without
     * the field have no text, so they come first in ascending order and last
     * in descending, and values that link to other resources have none
     * either. A page that only looks sorted would mislead a model.
     */
    protected function getSortHint(array $query, array $results, int $total): ?string
    {
        $term = $query['sort_by'] ?? '';
        if (!$results || !str_contains($term, ':')) {
            return null;
        }
        $label = $this->getLabel();
        $countQuery = array_diff_key($query, ['page' => true, 'per_page' => true]) + ['limit' => 0];
        $countQuery['property'][] = ['joiner' => 'and', 'property' => $term, 'type' => 'ex'];
        $withField = $this->api->search($this->getResourceName(), $countQuery)->getTotalResults();
        if (0 === $withField) {
            return sprintf(
                'None of the matching %s has a value for %s, so the results are not sorted by it. Sort by a field they use, as their full records show.',
                $label, $term
            );
        }
        $hints = [];
        if ($withField < $total) {
            $hints[] = sprintf(
                'Only %d of the %d matching %s have a value for %s. Those without one have nothing to sort on, so they are listed %s; a "where" condition with "exists" on %s leaves them out.',
                $withField, $total, $label, $term, 'asc' === $query['sort_order'] ? 'first' : 'last', $term
            );
        }
        $withValue = array_filter($results, fn ($resource) => $resource->value($term));
        $withText = array_filter($withValue, fn ($resource) => array_filter(
            $resource->value($term, ['all' => true]),
            fn ($value) => null !== $value->value()
        ));
        $withoutText = count($withValue) - count($withText);
        if ($withoutText) {
            // Core sorts on stored text, which links to resources and URIs
            // without a label don't have, and "exists" keeps these results.
            $hints[] = sprintf(
                '%d of the %s on this page have only links, to other resources or to URIs without a label, for %s. Those have no text to sort on, so they sort as if they had no value, and aren\'t in %s order.',
                $withoutText, $label, $term, $term
            );
        }
        return $hints ? implode(' ', $hints) : null;
    }
}
