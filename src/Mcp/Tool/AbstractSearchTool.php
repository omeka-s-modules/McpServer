<?php
namespace McpServer\Mcp\Tool;

use McpServer\Stdlib\ResourceFormatter;
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

    public function __construct(ApiManager $api, ResourceFormatter $formatter, ResourceQueryBuilder $queryBuilder)
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
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        return $this->search(new ArgumentReader($arguments));
    }

    /**
     * Get what the results are called in messages, such as "item sets".
     */
    protected function getLabel(): string
    {
        return $this->queryBuilder->label($this->getResourceName());
    }

    /**
     * Get how to do what a filter this search doesn't take would have done,
     * by filter name, for the error when it's given one.
     */
    protected function getFilterAdvice(): array
    {
        return [
            'item_set' => 'Find the items with search_items.',
            'has_media' => 'Find the items with search_items.',
            'media_type' => 'To filter by media type, call search_media.',
        ];
    }

    /**
     * Get query parameters from this tool's own arguments, beyond the shared
     * filters, such as an item whose media to list.
     */
    protected function getToolQuery(ArgumentReader $reader): array
    {
        return [];
    }

    /**
     * Get the sort to use when there's neither a sort nor a query, in place
     * of the newest first.
     *
     * @return array|null ['sort' => ..., 'order' => ...]
     */
    protected function getDefaultSort(array $toolQuery): ?array
    {
        return null;
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
     * Run the search and summarize a page of results, with a note when the
     * results need explaining.
     */
    protected function search(ArgumentReader $reader): ToolResult
    {
        $resourceName = $this->getResourceName();
        $page = $reader->getInt('page', 1, 1, self::PAGE_MAX);
        $perPage = $reader->getInt('per_page', $this->getPerPageDefault(), 1, self::PER_PAGE_MAX);
        $toolQuery = $this->getToolQuery($reader);
        $query = $this->queryBuilder->buildFilters($reader, $resourceName, $this->getFilterAdvice());
        $query += $this->queryBuilder->buildSort($reader, isset($query['fulltext_search']), $this->getDefaultSort($toolQuery));
        $query += $toolQuery + ['page' => $page, 'per_page' => $perPage];
        $response = $this->api->search($resourceName, $query);
        $results = $response->getContent();
        $this->formatter->primePublicUrls($results);
        $output = [
            'total_results' => $response->getTotalResults(),
            'page' => $page,
            'per_page' => $perPage,
            $resourceName => array_map(
                fn ($resource) => $this->summarize($resource) + $this->formatter->sortDate($resource, $query),
                $results
            ),
        ];
        $note = 0 === $output['total_results']
            ? $this->getEmptyNote($query)
            : $this->getSortNote($query, $results, $output['total_results']);
        return ToolResult::json($output, $note);
    }

    /**
     * Say why a search may have found nothing.
     */
    protected function getEmptyNote(array $query): string
    {
        return $this->queryBuilder->explainEmpty($query, $this->getLabel());
    }

    /**
     * Say when sorting by a property doesn't order the results as it seems to.
     *
     * Core sorts on a property's stored text, and silently. Results without
     * the field have no text, so they come first in ascending order and last
     * in descending, and values that link to other resources have none
     * either. A page that only looks sorted would mislead a model.
     */
    protected function getSortNote(array $query, array $results, int $total): ?string
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
        $notes = [];
        if ($withField < $total) {
            $notes[] = sprintf(
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
            $notes[] = sprintf(
                '%d of the %s on this page have only links, to other resources or to URIs without a label, for %s. Those have no text to sort on, so they sort as if they had no value, and aren\'t in %s order.',
                $withoutText, $label, $term, $term
            );
        }
        return $notes ? implode(' ', $notes) : null;
    }
}
