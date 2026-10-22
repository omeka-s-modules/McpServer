<?php
namespace McpServer\Stdlib;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Entity\Item;
use Omeka\Entity\Media;
use Omeka\Entity\Value;

/**
 * Counts resources and their values, as the caller may see them.
 *
 * The one place the module queries Doctrine directly, since Omeka's API can't
 * group. Every grouped query here follows the counting rule in ToolInterface:
 * the API first returns the IDs of the resources to count, applying every
 * filter, module extension, and visibility rule, and the query reads only rows
 * tied to those IDs. Core's Doctrine filters then hide private values and the
 * resources the caller can't see. Media are counted only by item IDs, since
 * core hides the media of private items in an API listener, not in its
 * filters.
 *
 * Tools in other modules that need counts should use this service, rather
 * than querying Doctrine themselves.
 */
class ResourceCounter
{
    // Most resource IDs fetched for grouped counts. Above this, callers count
    // a query at a time, or refuse.
    const COUNT_MAX = 200000;

    // Resource IDs fetched per search, which keeps memory low near the cap.
    const ID_PAGE = 20000;

    // What a value counts by, in DQL: its text, its linked resource's title,
    // or its URI, or "" for a visible linked resource with no title.
    const VALUE_LABEL = "COALESCE(vr.title, v.value, v.uri, '')";

    protected $api;

    protected $entityManager;

    protected $cache = [];

    public function __construct(ApiManager $api, EntityManager $entityManager)
    {
        $this->api = $api;
        $this->entityManager = $entityManager;
    }

    /**
     * Count the resources matching a query, once per request.
     *
     * @param string $resource "items", "media", or "item_sets"
     */
    public function countResources(string $resource, array $query = []): int
    {
        $key = $resource . json_encode($query);
        return $this->cache['counts'][$key] ??= $this->api->search(
            $resource,
            ['limit' => 0, 'sort_order' => 'asc'] + $query
        )->getTotalResults();
    }

    /**
     * Get the IDs of the resources matching a query, or null when there are
     * more than COUNT_MAX.
     *
     * Grouped queries limited to these IDs count only what the caller may see.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @return int[]|null
     */
    public function getResourceIds(string $resource, array $query = []): ?array
    {
        $total = $this->countResources($resource, $query);
        if ($total > static::COUNT_MAX) {
            return null;
        }
        // Core's full-text search reads sort_order without checking it's set.
        $query += ['sort_by' => 'id', 'sort_order' => 'asc'];
        // In pages, keeping only the integers: a scalar search builds a large
        // array per row, which would add up near the cap. The total is known,
        // so the pages skip core's count query.
        $ids = [];
        for ($page = 1; count($ids) < $total; $page++) {
            $pageIds = $this->api->search(
                $resource,
                ['page' => $page, 'per_page' => static::ID_PAGE] + $query,
                ['returnScalar' => 'id', 'countQuery' => false]
            )->getContent();
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
     * Count the visible items by class and by item set, and their media by
     * type, in three grouped queries, once per request.
     *
     * Item sets the caller can't see may get counts here, so a caller should
     * read only the counts of sets the API listed.
     *
     * @return array|null ['classes' => [id => items], 'item_sets' => [id =>
     *   items], 'media_types' => list of ['media_type', 'media', 'items']],
     *   or null above COUNT_MAX items
     */
    public function getGroupedCounts(): ?array
    {
        if (array_key_exists('grouped_counts', $this->cache)) {
            return $this->cache['grouped_counts'];
        }
        $counts = null;
        $itemIds = $this->getResourceIds('items');
        if (null !== $itemIds) {
            $counts = ['classes' => [], 'item_sets' => [], 'media_types' => []];
            if ($itemIds) {
                $counts['classes'] = $this->countItemsBy('IDENTITY(i.resourceClass)', $itemIds);
                $counts['item_sets'] = $this->countItemsBy('s.id', $itemIds, 'i.itemSets');
                $counts['media_types'] = $this->countMediaTypes($itemIds);
            }
        }
        return $this->cache['grouped_counts'] = $counts;
    }

    /**
     * Count the visible items in each of the given item sets.
     *
     * From the grouped counts when this request already has them, otherwise
     * a query per set, which costs less for a few sets than grouping every
     * item. Call getGroupedCounts() first to count many sets at once.
     *
     * @param int[] $itemSetIds
     * @return array Item counts keyed by item set ID
     */
    public function countItemsInSets(array $itemSetIds): array
    {
        return $this->countItemsPer('item_sets', 'item_set_id', $itemSetIds);
    }

    /**
     * Count the visible items of each of the given classes.
     *
     * From the grouped counts when this request already has them, otherwise
     * a query per class. Call getGroupedCounts() first to count many classes
     * at once.
     *
     * @param int[] $classIds
     * @return array Item counts keyed by class ID
     */
    public function countItemsByClass(array $classIds): array
    {
        return $this->countItemsPer('classes', 'resource_class_id', $classIds);
    }

    /**
     * Get a page of the values of a property among the given resources, with
     * how many resources have each.
     *
     * The database sorts and pages, so a field with many distinct values,
     * such as titles, never loads them all. A value counts by VALUE_LABEL. A
     * link to a resource the caller can't see has no label, so it doesn't
     * count.
     *
     * @param int[] $ids From getResourceIds()
     * @param bool $byValue Sort alphabetically by value, rather than most
     *   common first. Either order is stable, so pages don't overlap.
     * @return array List of ['label', 'resources']
     */
    public function countValues(int $propertyId, array $ids, int $limit, int $offset = 0, bool $byValue = false): array
    {
        $qb = $this->createValueQuery($propertyId, $ids)
            ->select(self::VALUE_LABEL . ' AS label', 'COUNT(DISTINCT v.resource) AS resources')
            ->groupBy('label');
        if ($byValue) {
            $qb->orderBy('label', 'ASC');
        } else {
            $qb->orderBy('resources', 'DESC')->addOrderBy('label', 'ASC');
        }
        return $qb->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Count the given resources that have a value of a property, and the
     * distinct values they have.
     *
     * @param int[] $ids From getResourceIds()
     * @return array ['resources' => int, 'values' => int]
     */
    public function countValueTotals(int $propertyId, array $ids): array
    {
        $row = $this->createValueQuery($propertyId, $ids)
            ->select('COUNT(DISTINCT v.resource) AS resources', 'COUNT(DISTINCT ' . self::VALUE_LABEL . ') AS distinct_values')
            ->getQuery()
            ->getSingleResult();
        return ['resources' => (int) $row['resources'], 'values' => (int) $row['distinct_values']];
    }

    /**
     * Count items per group, from the grouped counts when this request
     * already has them, otherwise a query per group.
     *
     * @param string $group A key of getGroupedCounts()
     * @param string $filter The item query parameter that selects one group
     */
    protected function countItemsPer(string $group, string $filter, array $ids): array
    {
        $grouped = $this->cache['grouped_counts'] ?? null;
        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = $grouped
                ? ($grouped[$group][$id] ?? 0)
                : $this->countResources('items', [$filter => $id]);
        }
        return $counts;
    }

    /**
     * Count the given items by an expression, optionally through a join.
     *
     * @return array Item counts keyed by the expression's value
     */
    protected function countItemsBy(string $expression, array $itemIds, ?string $join = null): array
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

    /**
     * Count the media of the given items by type, most media first.
     *
     * Media without a type, such as embedded video, aren't counted.
     *
     * @return array List of ['media_type', 'media', 'items']
     */
    protected function countMediaTypes(array $itemIds): array
    {
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
        return $mediaTypes;
    }

    /**
     * Start a query over the values of a property, limited to the given
     * resources, leaving out links to resources the caller can't see.
     */
    protected function createValueQuery(int $propertyId, array $ids)
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Value::class, 'v')
            ->leftJoin('v.valueResource', 'vr')
            ->where('v.property = :property')
            ->andWhere('v.resource IN (:resources)')
            ->andWhere('(vr.id IS NOT NULL OR v.value IS NOT NULL OR v.uri IS NOT NULL)')
            ->setParameter('property', $propertyId)
            ->setParameter('resources', $ids);
    }
}
