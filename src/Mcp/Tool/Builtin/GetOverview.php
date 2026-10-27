<?php
namespace McpServer\Mcp\Tool\Builtin;

use DateTime;
use McpServer\Mcp\Format\OutputLimits;
use McpServer\Mcp\Query\NameLookup;
use McpServer\Mcp\Query\ResourceCounter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Manager as ApiManager;

/**
 * Describes the whole installation, rather than one kind of resource: its
 * size, and the item sets, classes, resource templates, properties, media
 * types, and sites that searches filter by.
 *
 * The counts come from ResourceCounter, and cover only resources the API
 * returned. The classes and properties in use come from NameLookup.
 *
 * Later lists, such as filters other modules add, go here rather than into new
 * tools. Its output only gains keys, never loses or renames them.
 */
class GetOverview implements ToolInterface
{
    // The lists the overview shows.
    const LISTS = ['item_sets', 'media_types', 'classes', 'templates', 'properties', 'sites'];

    // Most entries of a list shown at once: the overview cuts each list here,
    // and "list" reads one this many at a time.
    const PER_PAGE = 200;

    protected $api;

    protected $counter;

    protected $names;

    public function __construct(ApiManager $api, ResourceCounter $counter, NameLookup $names)
    {
        $this->api = $api;
        $this->counter = $counter;
        $this->names = $names;
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
            . 'the classes items use, with counts; the resource templates items use, with counts (get_resource_template '
            . 'describes one); the properties in use, for "where" conditions, sorting, and count_values; and the sites, '
            . 'with their summaries (get_site describes one). Call this before filtering search_items by item set, '
            . sprintf('class, template, property, or site. A list longer than %d entries is cut; "list" and "page" read all of it.', self::PER_PAGE);
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'list' => [
                    'type' => 'string',
                    'enum' => self::LISTS,
                    'description' => sprintf('Return only this list, %d entries at a time.', self::PER_PAGE),
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => OutputLimits::PAGE_MAX,
                    'default' => 1,
                    'description' => 'With "list", the page of entries to return, starting at 1.',
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
        $name = $reader->getEnum('list', self::LISTS);
        $page = $reader->getInt('page', 1, 1, OutputLimits::PAGE_MAX);
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
                self::PER_PAGE, implode(', ', $cut)
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
            'per_page' => self::PER_PAGE,
            'entries' => $list['entries'],
        ];
        $pages = (int) ceil($list['total'] / self::PER_PAGE);
        $note = $page < $pages
            ? sprintf('There are %d entries on %d pages. Call again with "page" %d for the next.', $list['total'], $pages, $page + 1)
            : null;
        return ToolResult::json($output, $note);
    }

    /**
     * Get one page of a list, and its total.
     *
     * Item sets are paged by the API, since an installation may have many.
     * The other lists are short enough to fetch whole and cut here.
     *
     * @return array|null ['entries', 'total'], or null for media types when
     *   there are too many items to count them
     */
    protected function getList(string $name, int $page): ?array
    {
        if ('item_sets' === $name) {
            return $this->getItemSets($page);
        }
        $list = match ($name) {
            'media_types' => $this->getMediaTypes(),
            'classes' => array_map(fn ($class) => array_diff_key($class, ['id' => true]), $this->getClasses()),
            'templates' => $this->getTemplates(),
            'properties' => array_map(fn ($property) => array_diff_key($property, ['id' => true]), $this->names->getUsedProperties()),
            'sites' => $this->getSites(),
        };
        if (null === $list) {
            return null;
        }
        return [
            'entries' => array_slice($list, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => count($list),
        ];
    }

    /**
     * Get a page of the item sets, by title, with their item counts.
     *
     * @return array ['entries' => list of ['id', 'title', 'items'], 'total']
     */
    protected function getItemSets(int $page): array
    {
        // Counts every group in one query, which the per-group counts below
        // then read, rather than a query per group.
        $this->counter->getGroupedCounts();
        $response = $this->api->search('item_sets', [
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ]);
        $counts = $this->counter->countItemsInSets(array_map(fn ($itemSet) => $itemSet->id(), $response->getContent()));
        $itemSets = array_map(fn ($itemSet) => [
            'id' => $itemSet->id(),
            'title' => $itemSet->displayTitle(),
            'items' => $counts[$itemSet->id()],
        ], $response->getContent());
        return ['entries' => $itemSets, 'total' => $response->getTotalResults()];
    }

    /**
     * Get the classes items use, most used first, with their item counts.
     *
     * @return array List of ['id', 'term', 'label', 'items']
     */
    protected function getClasses(): array
    {
        // Counts every group in one query, which the per-group counts below
        // then read, rather than a query per group.
        $this->counter->getGroupedCounts();
        $counts = $this->counter->countItemsByClass(array_column($this->names->getUsedClasses(), 'id'));
        $classes = [];
        foreach ($this->names->getUsedClasses() as $class) {
            // Classes used only by media or item sets have no items.
            if ($counts[$class['id']]) {
                $classes[] = $class + ['items' => $counts[$class['id']]];
            }
        }
        usort($classes, fn ($a, $b) => [$b['items'], $a['term']] <=> [$a['items'], $b['term']]);
        return $classes;
    }

    /**
     * Get the resource templates items use, most used first, with their item
     * counts, and the class each gives its records.
     *
     * @return array List of ['id', 'label', 'class', 'items'], without empty
     *   fields
     */
    protected function getTemplates(): array
    {
        // Counts every group in one query, which the per-group counts below
        // then read, rather than a query per group.
        $this->counter->getGroupedCounts();
        $templates = $this->api->search('resource_templates', [])->getContent();
        $counts = $this->counter->countItemsByTemplate(array_map(fn ($template) => $template->id(), $templates));
        $entries = [];
        foreach ($templates as $template) {
            // Templates used only by media or item sets have no items.
            if ($counts[$template->id()]) {
                $entries[] = OutputLimits::withoutEmpty([
                    'id' => $template->id(),
                    'label' => $template->label(),
                    'class' => $template->resourceClass() ? $template->resourceClass()->term() : null,
                    'items' => $counts[$template->id()],
                ]);
            }
        }
        usort($entries, fn ($a, $b) => [$b['items'], $a['label']] <=> [$a['items'], $b['label']]);
        return $entries;
    }

    /**
     * Get the media types in use, most media first, with media and item counts.
     *
     * Media without a type, such as embedded video, aren't counted. Returns
     * null when there are too many items to count this way.
     *
     * @return array|null List of ['media_type', 'media', 'items']
     */
    protected function getMediaTypes(): ?array
    {
        $counts = $this->counter->getGroupedCounts();
        return $counts ? $counts['media_types'] : null;
    }

    /**
     * Get the sites, by title, with their summaries shortened.
     *
     * @return array List of ['slug', 'title', 'summary'], without empty fields
     */
    protected function getSites(): array
    {
        return array_map(fn ($site) => array_filter([
            'slug' => $site->slug(),
            'title' => $site->title(),
            'summary' => OutputLimits::shorten(trim((string) $site->summary()), OutputLimits::DESCRIPTION_LIMIT),
        ], fn ($field) => '' !== $field), $this->api->search('sites', ['sort_by' => 'title', 'sort_order' => 'asc'])->getContent());
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
        // Fetch only the latest date, which skips loading an item and core's
        // count query.
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
