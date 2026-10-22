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
            . 'set, class, field, or site.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function getAnnotations(): array
    {
        return Annotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $output = $this->getStats();
        [$itemSets, $itemSetsTotal] = $this->options->getItemSets();
        $output['item_sets'] = $itemSets;
        if ($itemSetsTotal > count($itemSets)) {
            $output['item_sets_total'] = $itemSetsTotal;
        }
        $mediaTypes = $this->options->getMediaTypes();
        $note = null === $mediaTypes ? sprintf(
            'Media types aren\'t counted on installations with more than %s items. search_media still filters by type.',
            number_format(ResourceCounter::COUNT_MAX)
        ) : null;
        $lists = [
            'media_types' => $mediaTypes,
            'classes' => array_map(fn ($class) => array_diff_key($class, ['id' => true]), $this->options->getClassCounts()),
            'properties' => array_map(fn ($property) => array_diff_key($property, ['id' => true]), $this->options->getProperties()),
            'sites' => array_map(fn ($site) => array_filter([
                'slug' => $site['slug'],
                'title' => $site['title'],
                'summary' => $this->formatter->shorten($site['summary'], ResourceFormatter::DESCRIPTION_LIMIT),
            ], fn ($field) => '' !== $field), $this->options->getSites()),
        ];
        foreach (array_filter($lists, fn ($list) => null !== $list) as $name => $list) {
            $output[$name] = array_slice($list, 0, SearchOptions::OVERVIEW_LIMIT);
            if (count($list) > SearchOptions::OVERVIEW_LIMIT) {
                $output[$name . '_total'] = count($list);
            }
        }
        return ToolResult::json($output, $note);
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
