<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Mcp\Format\OutputLimits;
use McpServer\Mcp\Format\ResourceFormatter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * The base of search_items, search_media, and search_item_sets, which take the
 * same filters, sort the same way, and return a page of short summaries at a
 * time, or with "fields", the values of the fields named.
 */
abstract class AbstractSearchTool implements ToolInterface
{
    const PER_PAGE_MAX = 50;

    // Most fields a search may ask each result to show.
    const FIELDS_MAX = 10;

    // Longest page a search with fields returns, in characters of JSON, since
    // assistants set longer results aside: Claude Code saves one over 50,000
    // characters to a file rather than showing it.
    const FIELDS_PAGE_LENGTH_MAX = 40000;

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
            'fields' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'maxItems' => self::FIELDS_MAX,
                'description' => sprintf(
                    'Property terms, such as "dcterms:rights", whose values each result should list: up to %d fields, and %d values of each. Each result then shows these fields in place of its summary, beside its id, title, and link, so name any others it should show, such as "dcterms:description". Reads fields across many results at once, instead of opening each one.',
                    self::FIELDS_MAX, OutputLimits::FIELD_VALUES_LIMIT
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
    protected function getFields(ArgumentReader $reader): array
    {
        $fields = [];
        foreach ($reader->getList('fields', self::FIELDS_MAX) as $term) {
            if (!is_string($term) || '' === trim($term)) {
                throw new ToolException('"fields" must list property terms, such as "dcterms:rights".');
            }
            $term = trim($term);
            $this->searchFilters->findProperty($term);
            $fields[$term] = $term;
        }
        return array_values($fields);
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
        $fields = $this->getFields($reader);
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
                fn ($resource) => ($fields ? $this->formatter->briefSummary($resource) : $this->summarize($resource))
                    + $this->formatter->sortDate($resource, $sortedDate)
                    + ($fields ? $this->formatter->fieldValues($resource, $fields) : []),
                $results
            ),
        ];
        if ($fields) {
            $length = mb_strlen(json_encode($output, ToolResult::JSON_FLAGS));
            if ($length > self::FIELDS_PAGE_LENGTH_MAX) {
                throw new ToolException(sprintf(
                    'With these fields, this page of %d results would be %s characters, more than the %s a page may have. Ask for "per_page" %d or fewer, or for fewer fields.',
                    count($results), number_format($length), number_format(self::FIELDS_PAGE_LENGTH_MAX),
                    max(1, intdiv(count($results) * self::FIELDS_PAGE_LENGTH_MAX, $length))
                ));
            }
        }
        $notes = [];
        $notes[] = 0 === $output['total_results']
            ? $this->getEmptyNote($query)
            : $this->getSortNote($query, $results, $output['total_results']);
        $notes[] = OutputLimitNotes::forSearchFields($output[$resourceName], $resourceName);
        $notes = array_filter($notes);
        return ToolResult::json($output, $notes ? implode(' ', $notes) : null);
    }

    /**
     * Say why a search may have found nothing.
     */
    protected function getEmptyNote(array $query): string
    {
        return $this->searchFilters->explainEmpty($query, $this->getNoun());
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
        $noun = $this->getNoun();
        $countQuery = array_diff_key($query, ['page' => true, 'per_page' => true]) + ['limit' => 0];
        $countQuery['property'][] = ['joiner' => 'and', 'property' => $term, 'type' => 'ex'];
        $withField = $this->api->search($this->getResourceName(), $countQuery)->getTotalResults();
        if (0 === $withField) {
            return sprintf(
                'None of the matching %s has a value for %s, so the results are not sorted by it. Sort by a field they use, as their full records show.',
                $noun, $term
            );
        }
        $notes = [];
        if ($withField < $total) {
            $notes[] = sprintf(
                'Only %d of the %d matching %s have a value for %s. Those without one have nothing to sort on, so they are listed %s; a "where" condition with "exists" on %s leaves them out.',
                $withField, $total, $noun, $term, 'asc' === $query['sort_order'] ? 'first' : 'last', $term
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
