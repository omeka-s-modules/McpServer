<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Format\OutputLimits;
use McpServer\Mcp\Format\ResourceFormatter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolException;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * The base of search_items, search_media, and search_item_sets, which take the
 * same filters, sort the same way, and return a page of short summaries at a
 * time, or with "properties", the values of the properties named.
 */
abstract class AbstractSearchTool implements ToolInterface
{
    const PER_PAGE_MAX = 50;

    // Most properties a search may ask each result to show.
    const PROPERTIES_MAX = 10;

    // Longest page a search with properties returns, in characters of JSON,
    // since assistants set longer results aside: Claude Code saves one over
    // 50,000 characters to a file rather than showing it.
    const PROPERTIES_PAGE_LENGTH_MAX = 40000;

    protected $api;

    protected $formatter;

    protected $searchFilters;

    public function __construct(ApiManager $api, ResourceFormatter $formatter, SearchFilters $searchFilters)
    {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->searchFilters = $searchFilters;
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
        return ['type' => 'object', 'properties' => $this->getSearchSchema(), 'additionalProperties' => false];
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
    protected function getNoun(): string
    {
        return $this->searchFilters->noun($this->getResourceName());
    }

    /**
     * Get advice for each filter this search doesn't take, by filter name,
     * saying how to get the same result another way. The error for such a
     * filter includes it.
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
     * Get the sort to use when a call gives neither "sort" nor "query", or
     * null for the usual default, newest first.
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
        return $this->searchFilters->getFilterSchema($this->getResourceName()) + [
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
                'maximum' => OutputLimits::PAGE_MAX,
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
            'properties' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'maxItems' => self::PROPERTIES_MAX,
                'description' => sprintf(
                    'Properties whose values each result should list, by term, such as "dcterms:rights": up to %d properties, and %d values of each. Each result then shows them in place of its summary, beside its id, title, and link, so name any others it should show, such as "dcterms:description". A value that links to another record gives that record\'s id. Reads properties (fields) across many results at once, instead of opening each one.',
                    self::PROPERTIES_MAX, OutputLimits::PROPERTY_VALUES_LIMIT
                ),
            ],
        ];
    }

    /**
     * Get the property terms whose values each result should show, each one
     * a property in use.
     *
     * @throws ToolException
     */
    protected function getPropertyTerms(ArgumentReader $reader): array
    {
        $terms = [];
        foreach ($reader->getList('properties', self::PROPERTIES_MAX) as $term) {
            if (!is_string($term) || '' === trim($term)) {
                throw new ToolException('"properties" must list property terms, such as "dcterms:rights".');
            }
            $term = trim($term);
            $this->searchFilters->findProperty($term);
            $terms[$term] = $term;
        }
        return array_values($terms);
    }

    /**
     * Run the search and summarize a page of results, with a note when the
     * results need explaining.
     */
    protected function search(ArgumentReader $reader): ToolResult
    {
        $resourceName = $this->getResourceName();
        $page = $reader->getInt('page', 1, 1, OutputLimits::PAGE_MAX);
        $perPage = $reader->getInt('per_page', $this->getPerPageDefault(), 1, self::PER_PAGE_MAX);
        $terms = $this->getPropertyTerms($reader);
        $toolQuery = $this->getToolQuery($reader);
        $query = $this->searchFilters->buildFilters($reader, $resourceName, $this->getFilterAdvice());
        $query += $this->searchFilters->buildSort($reader, isset($query['fulltext_search']), $this->getDefaultSort($toolQuery));
        $query += $toolQuery + ['page' => $page, 'per_page' => $perPage];
        $response = $this->api->search($resourceName, $query);
        $results = $response->getContent();
        $sortedDate = $this->searchFilters->sortedDate($query);
        $output = [
            'total_results' => $response->getTotalResults(),
            'page' => $page,
            'per_page' => $perPage,
            $resourceName => array_map(
                fn ($resource) => ($terms ? $this->formatter->briefSummary($resource) : $this->summarize($resource))
                    + $this->formatter->sortDate($resource, $sortedDate)
                    + ($terms ? $this->formatter->propertyValues($resource, $terms) : []),
                $results
            ),
        ];
        if ($terms) {
            $length = mb_strlen(json_encode($output, ToolResult::JSON_FLAGS));
            if ($length > self::PROPERTIES_PAGE_LENGTH_MAX) {
                throw new ToolException(sprintf(
                    'With these properties, this page of %d results would be %s characters, more than the %s a page may have. Ask for "per_page" %d or fewer, or for fewer properties.',
                    count($results), number_format($length), number_format(self::PROPERTIES_PAGE_LENGTH_MAX),
                    max(1, intdiv(count($results) * self::PROPERTIES_PAGE_LENGTH_MAX, $length))
                ));
            }
        }
        $notes = [];
        $notes[] = 0 === $output['total_results']
            ? $this->getEmptyNote($query)
            : $this->getSortNote($query, $results, $output['total_results']);
        $notes[] = OutputLimitNotes::forSearchProperties($output[$resourceName], $resourceName);
        $notes = array_filter($notes);
        return ToolResult::json($output, $notes ? implode(' ', $notes) : null);
    }

    /**
     * Say why a search may have found nothing.
     */
    protected function getEmptyNote(array $query): string
    {
        return $this->searchFilters->explainEmpty($this->getResourceName(), $query);
    }

    /**
     * Say when sorting by a property doesn't order the results as it seems to.
     *
     * Core sorts on a property's stored text, and silently. Results without
     * the property have no text, so they come first in ascending order and last
     * in descending, and values that link to other resources have none
     * either. A page that only looks sorted would mislead a model.
     */
    protected function getSortNote(array $query, array $results, int $total): ?string
    {
        $term = $query['sort_by'] ?? '';
        if (!$results || !str_contains($term, ':')) {
            return null;
        }
        $noun = $this->getNoun();
        $countQuery = array_diff_key($query, ['page' => true, 'per_page' => true]) + ['limit' => 0];
        $countQuery['property'][] = ['joiner' => 'and', 'property' => $term, 'type' => 'ex'];
        $withProperty = $this->api->search($this->getResourceName(), $countQuery)->getTotalResults();
        if (0 === $withProperty) {
            return sprintf(
                'None of the matching %s has a value for %s, so the results are not sorted by it. Sort by a property they use, as their full records show.',
                $noun, $term
            );
        }
        $notes = [];
        if ($withProperty < $total) {
            $notes[] = sprintf(
                'Only %d of the %d matching %s have a value for %s. Those without one have nothing to sort on, so they are listed %s; a "where" condition with "exists" on %s leaves them out.',
                $withProperty, $total, $noun, $term, 'asc' === $query['sort_order'] ? 'first' : 'last', $term
            );
        }
        $withValue = array_filter($results, fn ($resource) => $resource->value($term));
        $withText = array_filter($withValue, fn ($resource) => array_filter(
            $resource->value($term, ['all' => true]),
            fn ($value) => null !== $value->value()
        ));
        $withoutText = count($withValue) - count($withText);
        if ($withoutText) {
            // Core sorts on a value's stored text. Links to resources, and URIs
            // without a label, have none, yet "exists" still keeps these results.
            $notes[] = sprintf(
                '%d of the %s on this page have only links, to other resources or to URIs without a label, for %s. Those have no text to sort on, so they sort as if they had no value, and aren\'t in %s order.',
                $withoutText, $noun, $term, $term
            );
        }
        return $notes ? implode(' ', $notes) : null;
    }
}
