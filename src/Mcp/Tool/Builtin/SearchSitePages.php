<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Context;
use McpServer\Mcp\Output\OutputLimits;
use McpServer\Mcp\Output\SitePageFormatter;
use McpServer\Mcp\Query\IdResolver;
use McpServer\Mcp\Query\SearchFilters;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Manager as ApiManager;

/**
 * Searches the sites' pages by their text, their site, and the items they
 * show.
 *
 * Pages aren't resources, so this takes none of the other searches' filters
 * on properties and classes, and sorts only by what pages have.
 */
class SearchSitePages implements ToolInterface
{
    const PER_PAGE_DEFAULT = 20;

    const SORTS = ['relevance', 'added', 'modified', 'title'];

    protected $api;

    protected $formatter;

    protected $searchFilters;

    protected $idResolver;

    public function __construct(ApiManager $api, SitePageFormatter $formatter, SearchFilters $searchFilters, IdResolver $idResolver)
    {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->searchFilters = $searchFilters;
        $this->idResolver = $idResolver;
    }

    public function getTitle(): string
    {
        return 'Search site pages';
    }

    public function getDescription(): string
    {
        return 'Search the pages of the sites, such as exhibits and About pages, by their text, their site, or an '
            . 'item they show. Returns a page of short summaries: id, title, site, the page\'s address, and the start '
            . 'of its text, plus the total number of matches. Call get_site_page with an id to read one in full, with '
            . 'the items it shows. Use this for what the sites say about a subject, since search_items doesn\'t search pages. '
            . 'get_site gives a site\'s pages in the order its navigation shows them.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Words to search for in the pages\' titles and text, most relevant first. Finds pages with any of the words, ranking those with more of them first, and matches whole words only.',
                ],
                'site' => [
                    'type' => 'string',
                    'description' => 'Only pages of this site, by slug.',
                ],
                'item' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Only pages that show this item, by id, among whatever else they show. Finds pages that '
                        . 'attach it to a block, such as a media embed, not pages that list items by a query, such as a browse preview.',
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => self::SORTS,
                    'description' => '"relevance" (the default with a query), "added" (the default otherwise: when it was '
                        . 'added), "modified" (when it was last changed), or "title". Sorting by "added" or "modified" also gives each result that date.',
                ],
                'order' => [
                    'type' => 'string',
                    'enum' => ['asc', 'desc'],
                    'description' => 'Sort direction. Defaults to desc for "added" and "modified" and asc for "title". Relevance is always most relevant first.',
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
                    'maximum' => AbstractSearchTool::PER_PAGE_MAX,
                    'default' => self::PER_PAGE_DEFAULT,
                    'description' => sprintf('Results per page, from 1 to %d.', AbstractSearchTool::PER_PAGE_MAX),
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $reader = new ArgumentReader($arguments);
        $page = $reader->getInt('page', 1, 1, OutputLimits::PAGE_MAX);
        $perPage = $reader->getInt('per_page', self::PER_PAGE_DEFAULT, 1, AbstractSearchTool::PER_PAGE_MAX);
        $query = [];
        if (null !== ($text = $reader->getText('query'))) {
            $query['fulltext_search'] = $text;
        }
        if (null !== ($site = $reader->getText('site'))) {
            $query['site_id'] = $this->idResolver->findSite($site);
        }
        if (null !== ($itemId = $reader->getInt('item', null, 1))) {
            $query['item_id'] = $this->idResolver->findItem($itemId);
        }
        // Checked here, since buildSort() also takes property terms, which
        // pages don't have.
        $reader->getEnum('sort', self::SORTS);
        $query += $this->searchFilters->buildSort($reader, isset($query['fulltext_search']));
        $query += ['page' => $page, 'per_page' => $perPage];
        $response = $this->api->search('site_pages', $query);
        $sortedDate = $this->searchFilters->sortedDate($query);
        $output = [
            'total_results' => $response->getTotalResults(),
            'page' => $page,
            'per_page' => $perPage,
            'site_pages' => array_map(
                fn ($sitePage) => $this->formatter->summary($sitePage)
                    + ($sortedDate ? array_filter([$sortedDate => $this->formatter->dates($sitePage)[$sortedDate]]) : []),
                $response->getContent()
            ),
        ];
        return ToolResult::json($output, 0 === $output['total_results'] ? $this->getEmptyNote($query) : null);
    }

    /**
     * Say why a search may have found nothing.
     */
    protected function getEmptyNote(array $query): string
    {
        if (isset($query['item_id'])) {
            return 'No site pages matched. "item" finds only the pages that attach the item to a block, not those that list items by a query, such as a browse preview.';
        }
        if (isset($query['fulltext_search'])) {
            // As for the other searches: MySQL's full-text index skips
            // stopwords and short words.
            return 'No site pages matched. Full-text search ignores very common words and words shorter than three letters, so if the query was made only of those, try more specific words. Otherwise the pages may have nothing on this, and search_items searches the items.';
        }
        return isset($query['site_id']) ? 'This site has no pages you can see.' : 'There are no site pages you can see.';
    }
}
