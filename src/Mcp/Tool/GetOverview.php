<?php
namespace McpServer\Mcp\Tool;

use DateTime;
use McpServer\Stdlib\ResourceCounter;
use McpServer\Stdlib\ResourceFormatter;
use McpServer\Stdlib\SearchOptions;
use Omeka\Api\Manager as ApiManager;

/**
 * The one tool about the whole install rather than a resource type.
 *
 * Later lists, such as filters other modules add, go here rather than into
 * new tools. Its output only gains keys, never loses or renames them.
 */
class GetOverview implements ToolInterface
{
    // The lists, which the full overview cuts at OVERVIEW_LIMIT entries.
    const LISTS = ['item_sets', 'media_types', 'classes', 'properties', 'sites'];

    protected $api;

    protected $options;

    protected $counter;

    protected $formatter;

    public function __construct(ApiManager $api, SearchOptions $options, ResourceCounter $counter, ResourceFormatter $formatter)
    {
        $this->api = $api;
        $this->options = $options;
        $this->counter = $counter;
        $this->formatter = $formatter;
    }

    public function getTitle(): string
    {
        return 'Get overview';
    }

    public function getDescription(): string
    {
        return 'Get an overview of what is here: how many items there are, how many have media, how many media '
            . 'there are, and when an item was last added and last modified; the media types, with how many '
            . 'media and items have each; the item sets, with ids and item counts (get_item_set describes one); '
            . 'the classes items use, with counts; the properties in use, for "where" conditions, sorting, and '
            . 'count_values; and the sites, with their summaries. Call this before filtering search_items by item '
            . sprintf('set, class, field, or site. A list longer than %d entries is cut; "list" and "page" read all of it.', SearchOptions::OVERVIEW_LIMIT);
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'list' => [
                    'type' => 'string',
                    'enum' => self::LISTS,
                    'description' => sprintf('Return only this list, %d entries at a time.', SearchOptions::OVERVIEW_LIMIT),
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => AbstractSearchTool::PAGE_MAX,
                    'default' => 1,
                    'description' => 'With "list", the page of entries to return, starting at 1.',
                ],
            ],
        ];
    }

    public function getAnnotations(): array
    {
        return Annotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $reader = new ArgumentReader($arguments);
        $name = $reader->getEnum('list', self::LISTS);
        $page = $reader->getInt('page', 1, 1, AbstractSearchTool::PAGE_MAX);
        if (null !== $name) {
            return $this->getListResult($name, $page);
        }
        $output = $this->getStats();
        $notes = [];
        $cut = [];
        foreach (self::LISTS as $listName) {
            $list = $this->getList($listName, 1);
            if (null === $list) {
                $notes[] = $this->getMediaTypesNote();
                continue;
            }
            $output[$listName] = $list['entries'];
            if ($list['total'] > count($list['entries'])) {
                $output[$listName . '_total'] = $list['total'];
                $cut[] = sprintf('%s has %d', $listName, $list['total']);
            }
        }
        if ($cut) {
            $notes[] = sprintf(
                'Lists longer than %d entries are cut, and %s. Call get_overview with "list" and "page" to read all of one.',
                SearchOptions::OVERVIEW_LIMIT, implode(', ', $cut)
            );
        }
        return ToolResult::json($output, $notes ? implode(' ', $notes) : null);
    }

    /**
     * Get one page of one list, for the "list" argument.
     */
    protected function getListResult(string $name, int $page): ToolResult
    {
        $list = $this->getList($name, $page);
        if (null === $list) {
            return ToolResult::json(['list' => $name, 'entries' => []], $this->getMediaTypesNote());
        }
        $output = [
            'list' => $name,
            'total' => $list['total'],
            'page' => $page,
            'per_page' => SearchOptions::OVERVIEW_LIMIT,
            'entries' => $list['entries'],
        ];
        $pages = (int) ceil($list['total'] / SearchOptions::OVERVIEW_LIMIT);
        $note = $page < $pages
            ? sprintf('There are %d entries on %d pages. Call again with "page" %d for the next.', $list['total'], $pages, $page + 1)
            : null;
        return ToolResult::json($output, $note);
    }

    /**
     * Get one page of a list, and its total.
     *
     * @return array|null ['entries', 'total'], or null for media types when
     *   there are too many items to count them
     */
    protected function getList(string $name, int $page): ?array
    {
        if ('item_sets' === $name) {
            [$entries, $total] = $this->options->getItemSets($page);
            return ['entries' => $entries, 'total' => $total];
        }
        $list = match ($name) {
            'media_types' => $this->options->getMediaTypes(),
            'classes' => array_map(fn ($class) => array_diff_key($class, ['id' => true]), $this->options->getClassCounts()),
            'properties' => array_map(fn ($property) => array_diff_key($property, ['id' => true]), $this->options->getProperties()),
            'sites' => array_map(fn ($site) => array_filter([
                'slug' => $site['slug'],
                'title' => $site['title'],
                'summary' => $this->formatter->shorten($site['summary'], ResourceFormatter::DESCRIPTION_LIMIT),
            ], fn ($field) => '' !== $field), $this->options->getSites()),
        };
        if (null === $list) {
            return null;
        }
        return [
            'entries' => array_slice($list, ($page - 1) * SearchOptions::OVERVIEW_LIMIT, SearchOptions::OVERVIEW_LIMIT),
            'total' => count($list),
        ];
    }

    protected function getMediaTypesNote(): string
    {
        return sprintf(
            'Media types aren\'t counted on installations with more than %s items. search_media still filters by type.',
            number_format(ResourceCounter::COUNT_MAX)
        );
    }

    /**
     * Get counts and dates for the items as a whole.
     *
     * Item sets overlap, so their counts don't add up to the item total.
     * Adding an item also sets its modified date, so the last modified date is
     * never earlier than the last added.
     */
    protected function getStats(): array
    {
        // The latest date alone, which skips loading an item and core's count
        // query.
        $latest = function (string $field): ?string {
            $dates = $this->api->search(
                'items',
                ['sort_by' => $field, 'sort_order' => 'desc', 'limit' => 1],
                ['returnScalar' => $field, 'countQuery' => false]
            )->getContent();
            $date = reset($dates);
            return $date ? (new DateTime($date))->format('c') : null;
        };
        return array_filter([
            'items' => $this->counter->countResources('items'),
            'items_with_media' => $this->counter->countResources('items', ['has_media' => 1]),
            'media' => $this->counter->countResources('media'),
            'items_last_added' => $latest('created'),
            'items_last_modified' => $latest('modified'),
        ], fn ($stat) => null !== $stat);
    }
}
