<?php
namespace McpServer\Mcp\Query;

use Omeka\Api\Manager as ApiManager;

/**
 * Builds the lists get_overview shows: the item sets with their item counts,
 * the classes items use with theirs, the media types, the properties in use,
 * and the sites.
 *
 * The counts come from ResourceCounter, and cover only resources the API
 * returned. The classes and properties in use come from NameLookup. Each list
 * is fetched once per request.
 */
class OverviewLists
{
    // Most entries a list returns; its total says when there are more.
    const LIMIT = 200;

    protected $api;

    protected $counter;

    protected $names;

    protected $cache = [];

    public function __construct(ApiManager $api, ResourceCounter $counter, NameLookup $names)
    {
        $this->api = $api;
        $this->counter = $counter;
        $this->names = $names;
    }

    /**
     * Get a page of the item sets, by title, with their item counts.
     *
     * @return array [list of ['id', 'title', 'items'], total]
     */
    public function getItemSets(int $page = 1): array
    {
        return $this->cache['item_sets'][$page] ??= (function () use ($page) {
            // Counts every set at once, rather than a query per set.
            $this->counter->getGroupedCounts();
            $response = $this->api->search('item_sets', [
                'sort_by' => 'title',
                'sort_order' => 'asc',
                'page' => $page,
                'per_page' => self::LIMIT,
            ]);
            $counts = $this->counter->countItemsInSets(array_map(fn ($itemSet) => $itemSet->id(), $response->getContent()));
            $itemSets = array_map(fn ($itemSet) => [
                'id' => $itemSet->id(),
                'title' => $itemSet->displayTitle(),
                'items' => $counts[$itemSet->id()],
            ], $response->getContent());
            return [$itemSets, $response->getTotalResults()];
        })();
    }

    /**
     * Get the classes items use, most used first, with their item counts.
     *
     * @return array List of ['id', 'term', 'label', 'items']
     */
    public function getClasses(): array
    {
        return $this->cache['class_counts'] ??= (function () {
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
        })();
    }

    /**
     * Get the media types in use, most media first, with media and item counts.
     *
     * Media without a type, such as embedded video, aren't counted. Returns
     * null when there are too many items to count this way.
     *
     * @return array|null List of ['media_type', 'media', 'items']
     */
    public function getMediaTypes(): ?array
    {
        $counts = $this->counter->getGroupedCounts();
        return $counts ? $counts['media_types'] : null;
    }

    /**
     * Get the properties in use, by term.
     *
     * @return array List of ['id', 'term', 'label']
     */
    public function getProperties(): array
    {
        return $this->names->getUsedProperties();
    }

    /**
     * Get the sites, by title.
     *
     * @return array List of ['id', 'slug', 'title', 'summary']
     */
    public function getSites(): array
    {
        return $this->cache['sites'] ??= array_map(fn ($site) => [
            'id' => $site->id(),
            'slug' => $site->slug(),
            'title' => $site->title(),
            'summary' => trim((string) $site->summary()),
        ], $this->api->search('sites', ['sort_by' => 'title', 'sort_order' => 'asc'])->getContent());
    }
}
