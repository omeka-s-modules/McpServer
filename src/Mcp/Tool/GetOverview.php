<?php
namespace McpServer\Mcp\Tool;

use McpServer\Stdlib\ItemFormatter;
use McpServer\Stdlib\SearchOptions;
use Omeka\Api\Manager as ApiManager;

/**
 * The one tool about the whole install rather than a resource type.
 *
 * Later lists, such as media types or filters other modules add, go here
 * rather than into new tools. Its output only gains keys, never loses or
 * renames them.
 */
class GetOverview implements ToolInterface
{
    protected $api;

    protected $options;

    protected $formatter;

    public function __construct(ApiManager $api, SearchOptions $options, ItemFormatter $formatter)
    {
        $this->api = $api;
        $this->options = $options;
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
        if (null === $mediaTypes) {
            $output['media_types_note'] = sprintf(
                'Media types aren\'t counted on installations with more than %d items. search_media still filters by type.',
                SearchOptions::COUNT_MAX
            );
        }
        $lists = [
            'media_types' => $mediaTypes,
            'classes' => array_map(fn ($class) => array_diff_key($class, ['id' => true]), $this->options->getClassCounts()),
            'properties' => array_map(fn ($property) => array_diff_key($property, ['id' => true]), $this->options->getProperties()),
            'sites' => array_map(fn ($site) => array_filter([
                'slug' => $site['slug'],
                'title' => $site['title'],
                'summary' => $this->formatter->shorten($site['summary'], ItemFormatter::DESCRIPTION_LIMIT),
            ], fn ($field) => '' !== $field), $this->options->getSites()),
        ];
        foreach (array_filter($lists, fn ($list) => null !== $list) as $name => $list) {
            $output[$name] = array_slice($list, 0, SearchOptions::LIMIT);
            if (count($list) > SearchOptions::LIMIT) {
                $output[$name . '_total'] = count($list);
            }
        }
        return ToolResult::json($output);
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
        $count = fn ($resource, $query = []) => $this->api->search($resource, $query + ['limit' => 0])->getTotalResults();
        $latest = fn ($sortBy) => $this->api->search('items', ['sort_by' => $sortBy, 'sort_order' => 'desc', 'limit' => 1])->getContent()[0] ?? null;
        $added = $latest('created');
        $modified = $latest('modified');
        return array_filter([
            'items' => $count('items'),
            'items_with_media' => $count('items', ['has_media' => 1]),
            'media' => $count('media'),
            'items_last_added' => $added ? $added->created()->format('c') : null,
            'items_last_modified' => ($modified && $modified->modified()) ? $modified->modified()->format('c') : null,
        ], fn ($stat) => null !== $stat);
    }
}
