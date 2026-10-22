<?php
namespace McpServer\Stdlib;

use Doctrine\ORM\EntityManager;
use McpServer\Mcp\Exception\ToolException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Entity\Item;
use Omeka\Entity\Media;

/**
 * What search can filter on, as the caller may see it, with counts.
 *
 * Shared by the search tools, which resolve terms, labels, and slugs here, and
 * by get_overview, which lists them. Everything comes from the API, or from
 * grouped counts limited to what the API returned, so it reflects the
 * caller's visibility. Each list is fetched once per request.
 */
class SearchOptions
{
    // Most entries a list returns; its total says when there are more.
    const LIMIT = 200;

    // Most resource IDs fetched for grouped counts. Above this, counts take a
    // query each, or are refused or left out.
    const COUNT_MAX = 200000;

    // Resource IDs fetched per search, which keeps memory low near the cap.
    const ID_PAGE = 20000;

    // Most classes an error lists, so a model can correct a class name.
    const CLASSES_IN_ERROR_MAX = 50;

    protected $api;

    protected $entityManager;

    protected $lists = [];

    public function __construct(ApiManager $api, EntityManager $entityManager)
    {
        $this->api = $api;
        $this->entityManager = $entityManager;
    }

    /**
     * Get the IDs of the resources matching a query, or null when there are
     * more than COUNT_MAX.
     *
     * The API applies every filter, module extension, and visibility rule,
     * including core's hiding of media on private items, so grouped queries
     * limited to these IDs count only what the caller may see.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @return int[]|null
     */
    public function getResourceIds(string $resource, array $query = []): ?array
    {
        // Core's full-text search reads sort_order without checking it's set.
        $query += ['sort_by' => 'id', 'sort_order' => 'asc'];
        $total = $this->api->search($resource, ['limit' => 0] + $query)->getTotalResults();
        if ($total > static::COUNT_MAX) {
            return null;
        }
        // In pages, keeping only the integers: a scalar search builds a large
        // array per row, which would add up near the cap.
        $ids = [];
        for ($page = 1; count($ids) < $total; $page++) {
            $pageIds = $this->api->search($resource, ['page' => $page, 'per_page' => static::ID_PAGE] + $query, ['returnScalar' => 'id'])->getContent();
            if (!$pageIds) {
                break;
            }
            foreach ($pageIds as $id) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /**
     * Get the item sets, by title, with their item counts.
     *
     * @return array [list of ['id', 'title', 'items'], total]
     */
    public function getItemSets(): array
    {
        return $this->lists['item_sets'] ??= (function () {
            // Counts every set at once, rather than a query per set.
            $this->getGroupedCounts();
            $response = $this->api->search('item_sets', ['sort_by' => 'title', 'sort_order' => 'asc', 'limit' => self::LIMIT]);
            $itemSets = array_map(fn ($itemSet) => [
                'id' => $itemSet->id(),
                'title' => $itemSet->displayTitle(),
                'items' => $this->countItemsInSet($itemSet),
            ], $response->getContent());
            return [$itemSets, $response->getTotalResults()];
        })();
    }

    /**
     * Count the items the caller may see in an item set.
     *
     * Read from the grouped counts when this request already has them, as
     * get_overview does. Otherwise one count query, which is cheaper than
     * grouping everything for a single set or a page of sets.
     */
    public function countItemsInSet(ItemSetRepresentation $itemSet): int
    {
        $counts = $this->lists['grouped_counts'] ?? null;
        return $counts ? ($counts['item_sets'][$itemSet->id()] ?? 0) : $itemSet->itemCount();
    }

    /**
     * Get the classes resources use, by term.
     *
     * Enough to resolve a class name, without counting.
     *
     * @return array List of ['id', 'term', 'label']
     */
    public function getClasses(): array
    {
        return $this->lists['classes'] ??= (function () {
            $classes = array_map(fn ($class) => [
                'id' => $class->id(),
                'term' => $class->term(),
                'label' => $class->label(),
            ], $this->api->search('resource_classes', ['used' => true])->getContent());
            usort($classes, fn ($a, $b) => strcmp($a['term'], $b['term']));
            return $classes;
        })();
    }

    /**
     * Get the classes items use, most used first, with their item counts.
     *
     * @return array List of ['id', 'term', 'label', 'items']
     */
    public function getClassCounts(): array
    {
        return $this->lists['class_counts'] ??= (function () {
            $counts = $this->getGroupedCounts();
            $classes = [];
            foreach ($this->getClasses() as $class) {
                // Classes used only by media or item sets have no items.
                $items = $counts
                    ? ($counts['classes'][$class['id']] ?? 0)
                    : $this->api->search('items', ['resource_class_id' => $class['id'], 'limit' => 0])->getTotalResults();
                if ($items) {
                    $classes[] = $class + ['items' => $items];
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
        $counts = $this->getGroupedCounts();
        return $counts ? $counts['media_types'] : null;
    }

    /**
     * Get the properties in use, by term.
     *
     * @return array List of ['id', 'term', 'label']
     */
    public function getProperties(): array
    {
        return $this->lists['properties'] ??= (function () {
            $properties = array_map(fn ($property) => [
                'id' => $property->id(),
                'term' => $property->term(),
                'label' => $property->label(),
            ], $this->api->search('properties', ['used' => true])->getContent());
            usort($properties, fn ($a, $b) => strcmp($a['term'], $b['term']));
            return $properties;
        })();
    }

    /**
     * Get the sites, by title.
     *
     * @return array List of ['id', 'slug', 'title', 'summary']
     */
    public function getSites(): array
    {
        return $this->lists['sites'] ??= array_map(fn ($site) => [
            'id' => $site->id(),
            'slug' => $site->slug(),
            'title' => $site->title(),
            'summary' => trim((string) $site->summary()),
        ], $this->api->search('sites', ['sort_by' => 'title', 'sort_order' => 'asc'])->getContent());
    }

    /**
     * Find the class a term or label names, among the classes in use.
     *
     * @throws ToolException When nothing matches, or a label matches several
     */
    public function findClass(string $termOrLabel): int
    {
        if (str_contains($termOrLabel, ':')) {
            // One targeted query, rather than every class in use.
            $classes = $this->api->search('resource_classes', ['term' => $termOrLabel, 'used' => true, 'limit' => 1])->getContent();
            if ($classes) {
                return $classes[0]->id();
            }
            $matches = [];
        } else {
            // Core can't search classes by label, so labels need the list.
            $matches = array_values(array_filter(
                $this->getClasses(),
                fn ($class) => 0 === strcasecmp($class['label'], $termOrLabel)
            ));
        }
        if (1 === count($matches)) {
            return $matches[0]['id'];
        }
        if (!$matches) {
            // get_overview lists only the classes items use, so a class used
            // only by media or item sets is named here.
            $classes = $this->getClasses();
            if (count($classes) <= self::CLASSES_IN_ERROR_MAX) {
                throw new ToolException(sprintf(
                    'Nothing uses the class "%s". The classes in use are: %s.',
                    $termOrLabel,
                    implode(', ', array_map(fn ($class) => sprintf('%s (%s)', $class['term'], $class['label']), $classes))
                ));
            }
            throw new ToolException(sprintf('Nothing uses the class "%s". Call get_overview to see the classes items use.', $termOrLabel));
        }
        throw new ToolException(sprintf(
            'The label "%s" matches several classes. Use one of their terms instead: %s.',
            $termOrLabel, implode(', ', array_column($matches, 'term'))
        ));
    }

    /**
     * Find the property a term names, among the properties in use.
     *
     * @throws ToolException When no property in use has the term
     */
    public function findProperty(string $term): int
    {
        // One targeted query per term, rather than every property in use,
        // which joins the whole value table.
        $this->lists['property_ids'][$term] ??= (function () use ($term) {
            $properties = $this->api->search('properties', ['term' => $term, 'used' => true, 'limit' => 1])->getContent();
            return $properties ? $properties[0]->id() : null;
        })();
        if (null !== $this->lists['property_ids'][$term]) {
            return $this->lists['property_ids'][$term];
        }
        throw new ToolException(sprintf('No values use the property "%s". Call get_overview to see the properties in use.', $term));
    }

    /**
     * Find the site a slug names.
     *
     * @throws ToolException When no visible site has the slug
     */
    public function findSite(string $slug): int
    {
        foreach ($this->getSites() as $site) {
            if ($site['slug'] === $slug) {
                return $site['id'];
            }
        }
        throw new ToolException(sprintf('There is no site "%s". Call get_overview to see the sites.', $slug));
    }

    /**
     * Count the visible items by class and by item set, and their media by
     * type, in three grouped queries.
     *
     * Follows the counting rule in ToolInterface: the queries read only the
     * items whose IDs the API returned, and media only by those items, since
     * core hides the media of private items in the API rather than in its
     * Doctrine filters. Item sets the caller can't see may get counts here,
     * but getItemSets() only reads the counts of sets the API listed.
     *
     * @return array|null ['classes' => [id => items], 'item_sets' => [id =>
     *   items], 'media_types' => list], or null above COUNT_MAX items
     */
    protected function getGroupedCounts(): ?array
    {
        if (array_key_exists('grouped_counts', $this->lists)) {
            return $this->lists['grouped_counts'];
        }
        $counts = null;
        $itemIds = $this->getResourceIds('items');
        if (null !== $itemIds) {
            $counts = ['classes' => [], 'item_sets' => [], 'media_types' => []];
            if ($itemIds) {
                $counts['classes'] = $this->countBy('IDENTITY(i.resourceClass)', $itemIds);
                $counts['item_sets'] = $this->countBy('s.id', $itemIds, 'i.itemSets');
                $rows = $this->entityManager->createQueryBuilder()
                    ->select('m.mediaType AS media_type', 'COUNT(m.id) AS media', 'COUNT(DISTINCT m.item) AS items')
                    ->from(Media::class, 'm')
                    ->where('m.item IN (:items)')
                    ->andWhere('m.mediaType IS NOT NULL')
                    ->groupBy('media_type')
                    ->setParameter('items', $itemIds)
                    ->getQuery()
                    ->getScalarResult();
                $mediaTypes = array_map(fn ($row) => [
                    'media_type' => $row['media_type'],
                    'media' => (int) $row['media'],
                    'items' => (int) $row['items'],
                ], $rows);
                usort($mediaTypes, fn ($a, $b) => [$b['media'], $a['media_type']] <=> [$a['media'], $b['media_type']]);
                $counts['media_types'] = $mediaTypes;
            }
        }
        return $this->lists['grouped_counts'] = $counts;
    }

    /**
     * Count the given items by an expression, optionally through a join.
     *
     * @return array Item counts keyed by the expression's value
     */
    protected function countBy(string $expression, array $itemIds, ?string $join = null): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select("$expression AS group_id", 'COUNT(DISTINCT i.id) AS items')
            ->from(Item::class, 'i')
            ->where('i.id IN (:items)')
            ->groupBy('group_id')
            ->setParameter('items', $itemIds);
        if ($join) {
            $qb->innerJoin($join, 's');
        }
        $counts = [];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            if (null !== $row['group_id']) {
                $counts[(int) $row['group_id']] = (int) $row['items'];
            }
        }
        return $counts;
    }
}
