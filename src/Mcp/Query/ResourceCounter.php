<?php
namespace McpServer\Mcp\Query;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Entity\Item;
use Omeka\Entity\Media;
use Omeka\Entity\Resource;
use Omeka\Entity\Value;
use Omeka\Entity\ValueAnnotation;
use OverflowException;

/**
 * Counts items, media, and item sets, and the values and properties among them,
 * including only what the caller can see.
 *
 * This is the one place the module queries Doctrine directly, since Omeka's
 * API can't group. Every grouped count follows the counting rule in
 * ToolInterface. It takes an API resource and query, not IDs, and gets the
 * IDs of the matching resources from the API itself, through
 * getResourceIds(), so every filter, module extension, and visibility rule
 * applies, whoever calls it. Then the query reads only rows tied to those
 * IDs, and core's Doctrine filters hide private values and links to private
 * resources. Those filters check only each value's own visibility, never its
 * resource's, so a private item's public values stay out only because its ID
 * isn't among them. Media are counted only by item IDs, because core hides
 * the media of private items in an API listener, which Doctrine queries don't
 * pass through.
 *
 * It also checks whether a property or class is in use. Core's "used" filter
 * answers that by joining every value or resource, which takes seconds on a
 * large installation. isUsed() reads one row instead, through the same
 * Doctrine filters, so it gives the same answer. isUsedOnlyInAnnotations()
 * works the same way, reading a row or two.
 *
 * Tools in other modules that need counts should use this service, rather than
 * querying Doctrine themselves. Above COUNT_MAX matching resources, the
 * counts throw, so check getResourceIds() for null first.
 */
class ResourceCounter
{
    // Most resource IDs fetched for grouped counts. Above this, callers count
    // a query at a time, or refuse.
    const COUNT_MAX = 200000;

    // Resource IDs fetched per search. Core sorts the whole match for every
    // page, so each costs about the same, and pages are as large as memory
    // allows: the cap takes four, adding about 30 MB.
    const ID_PAGE = 50000;

    // What a value counts by, in DQL: its text, its linked resource's title,
    // or its URI, or "" for a visible linked resource with no title.
    const VALUE_TEXT = "COALESCE(vr.title, v.value, v.uri, '')";

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
     * Get the IDs of the resources matching a query, once per request, or
     * null when there are more than COUNT_MAX.
     *
     * The grouped counts take the same resource and query, and get their IDs
     * here, so they count only what the API shows the caller.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @return int[]|null
     */
    public function getResourceIds(string $resource, array $query = []): ?array
    {
        $key = $resource . json_encode($query);
        if (!array_key_exists($key, $this->cache['ids'] ?? [])) {
            $this->cache['ids'][$key] = $this->fetchResourceIds($resource, $query);
        }
        return $this->cache['ids'][$key];
    }

    /**
     * Fetch the IDs getResourceIds() returns, through the API.
     */
    protected function fetchResourceIds(string $resource, array $query): ?array
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
     * Whether a property or class is in use, exactly as core's "used" filter
     * answers: whether any value of the property, or any resource of the
     * class, passes core's Doctrine filters.
     *
     * Like that filter, it counts a public value on a private item, and a
     * public media of a private item, which the API would hide. So it can
     * name a term that only hidden resources use, which core's API already
     * tells anyone who asks.
     *
     * @param string $resource "properties" or "resource_classes"
     * @throws \UnhandledMatchError For any other resource
     */
    public function isUsed(string $resource, int $id): bool
    {
        [$entity, $field] = match ($resource) {
            'properties' => [Value::class, 'property'],
            'resource_classes' => [Resource::class, 'resourceClass'],
        };
        return (bool) $this->entityManager->createQueryBuilder()
            ->select('x.id')
            ->from($entity, 'x')
            ->where("x.$field = :id")
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Whether every value of a property that the caller can see is in a value
     * annotation, so that none is on an item, media, or item set.
     *
     * It reads one row, like isUsed(), and a second only when the first finds
     * nothing. It reveals no more than core's "used" filter does, apart from
     * whether the values are annotations.
     */
    public function isUsedOnlyInAnnotations(int $propertyId): bool
    {
        return !$this->hasVisibleValue($propertyId, false) && $this->hasVisibleValue($propertyId, true);
    }

    /**
     * Get the properties whose values, on the given resources, carry
     * annotations that use a given property. For a qualifier used only in
     * annotations, for example, it finds the date properties whose values
     * those annotations qualify.
     *
     * Annotations can't be searched or counted, so this tells a caller where
     * to read them instead.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @param array $query An API query, as for getResourceIds()
     * @return string[] Their terms, in order
     */
    public function getAnnotatedProperties(string $resource, array $query, int $propertyId): array
    {
        $ids = $this->getCountedIds($resource, $query);
        if (!$ids) {
            return [];
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select("DISTINCT CONCAT(vocabulary.prefix, ':', property.localName) AS term")
            ->from(Value::class, 'v')
            ->innerJoin('v.valueAnnotation', 'a')
            ->innerJoin(Value::class, 'av', 'WITH', 'av.resource = a')
            ->innerJoin('v.property', 'property')
            ->innerJoin('property.vocabulary', 'vocabulary')
            ->where('v.resource IN (:resources)')
            ->andWhere('av.property = :property')
            ->orderBy('term')
            ->setParameter('resources', $ids)
            ->setParameter('property', $propertyId)
            ->getQuery()
            ->getScalarResult();
        return array_column($rows, 'term');
    }

    /**
     * Count the visible items by class, item set, and resource template, and
     * their media by type, in four grouped queries, once per request.
     *
     * Counts only the item sets the caller may see, since core's filters
     * apply to the sets the count joins.
     *
     * @return array|null ['classes' => [id => items], 'item_sets' => [id =>
     *   items], 'templates' => [id => items], 'media_types' => list of
     *   ['media_type', 'media', 'items']], or null above COUNT_MAX items
     */
    public function getGroupedCounts(): ?array
    {
        if (array_key_exists('grouped_counts', $this->cache)) {
            return $this->cache['grouped_counts'];
        }
        $counts = null;
        $itemIds = $this->getResourceIds('items');
        if (null !== $itemIds) {
            $counts = ['classes' => [], 'item_sets' => [], 'templates' => [], 'media_types' => []];
            if ($itemIds) {
                $counts['classes'] = $this->countItemsBy('IDENTITY(i.resourceClass)', $itemIds);
                $counts['item_sets'] = $this->countItemsBy('s.id', $itemIds, 'i.itemSets');
                $counts['templates'] = $this->countItemsBy('IDENTITY(i.resourceTemplate)', $itemIds);
                $counts['media_types'] = $this->countMediaTypes($itemIds);
            }
        }
        return $this->cache['grouped_counts'] = $counts;
    }

    /**
     * Count the visible items in each of the given item sets, leaving out
     * the sets the caller can't see, so a count can't reveal one. The API's
     * item set filter doesn't check the set itself.
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
        $visible = $this->getVisibleItemSetIds();
        return $this->countItemsPer('item_sets', 'item_set_id', array_filter($itemSetIds, fn ($id) => isset($visible[$id])));
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
     * Count the visible items using each of the given resource templates.
     *
     * From the grouped counts when this request already has them, otherwise
     * a query per template. Call getGroupedCounts() first to count many
     * templates at once.
     *
     * @param int[] $templateIds
     * @return array Item counts keyed by template ID
     */
    public function countItemsByTemplate(array $templateIds): array
    {
        return $this->countItemsPer('templates', 'resource_template_id', $templateIds);
    }

    /**
     * Get a page of the values of a property among the given resources, with
     * how many resources have each.
     *
     * The database sorts and pages, so a property with many distinct values,
     * such as titles, never loads them all. A value counts by VALUE_TEXT. A
     * link to a resource the caller can't see has no label, so it doesn't
     * count.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @param array $query An API query, as for getResourceIds()
     * @param bool $byValue Sort alphabetically by value, rather than most
     *   common first. Either order is stable, so pages don't overlap.
     * @param int|null $prefixLength Count values by this many of their first
     *   characters, rather than whole
     * @return array List of ['text', 'resources']
     * @throws OverflowException Above COUNT_MAX matching resources
     */
    public function countValues(string $resource, array $query, int $propertyId, int $limit, int $offset = 0, bool $byValue = false, ?int $prefixLength = null): array
    {
        $ids = $this->getCountedIds($resource, $query);
        if (!$ids) {
            return [];
        }
        $qb = $this->createValueQuery($propertyId, $ids)
            ->select($this->valueText($prefixLength) . ' AS text', 'COUNT(DISTINCT v.resource) AS resources')
            ->groupBy('text');
        if ($byValue) {
            $qb->orderBy('text', 'ASC');
        } else {
            $qb->orderBy('resources', 'DESC')->addOrderBy('text', 'ASC');
        }
        return $qb->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Count the given resources that have each property.
     *
     * A value that links to a resource the caller can't see doesn't count, as
     * get_item doesn't show it.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @param array $query An API query, as for getResourceIds()
     * @return array List of ['property' => property ID, 'resources' => count]
     * @throws OverflowException Above COUNT_MAX matching resources
     */
    public function countProperties(string $resource, array $query): array
    {
        $ids = $this->getCountedIds($resource, $query);
        if (!$ids) {
            return [];
        }
        return $this->createValueQuery(null, $ids)
            ->select('IDENTITY(v.property) AS property', 'COUNT(DISTINCT v.resource) AS resources')
            ->groupBy('v.property')
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Count the given resources that have each property in each language,
     * most first, leaving out values with no language tag.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @param array $query An API query, as for getResourceIds()
     * @return array List of ['property' => property ID, 'lang', 'resources' => count]
     */
    public function countPropertyLanguages(string $resource, array $query): array
    {
        $ids = $this->getCountedIds($resource, $query);
        if (!$ids) {
            return [];
        }
        return $this->createValueQuery(null, $ids)
            ->select('IDENTITY(v.property) AS property', 'v.lang AS lang', 'COUNT(DISTINCT v.resource) AS resources')
            ->andWhere("v.lang IS NOT NULL AND v.lang <> ''")
            ->groupBy('v.property')
            ->addGroupBy('v.lang')
            ->orderBy('resources', 'DESC')
            ->addOrderBy('lang')
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Count the given resources that have a value of a property, and the
     * distinct values they have.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @param array $query An API query, as for getResourceIds()
     * @param int|null $prefixLength As for countValues()
     * @return array ['resources' => int, 'values' => int]
     */
    public function countValueTotals(string $resource, array $query, int $propertyId, ?int $prefixLength = null): array
    {
        $ids = $this->getCountedIds($resource, $query);
        if (!$ids) {
            return ['resources' => 0, 'values' => 0];
        }
        $row = $this->createValueQuery($propertyId, $ids)
            ->select('COUNT(DISTINCT v.resource) AS resources', 'COUNT(DISTINCT ' . $this->valueText($prefixLength) . ') AS distinct_values')
            ->getQuery()
            ->getSingleResult();
        return ['resources' => (int) $row['resources'], 'values' => (int) $row['distinct_values']];
    }

    /**
     * Get the IDs a grouped count reads, from the API.
     *
     * Above COUNT_MAX it throws, rather than counting nothing, so a caller
     * that didn't check getResourceIds() first can't report an empty count.
     *
     * @return int[]
     * @throws OverflowException
     */
    protected function getCountedIds(string $resource, array $query): array
    {
        $ids = $this->getResourceIds($resource, $query);
        if (null === $ids) {
            throw new OverflowException(sprintf(
                'More than %d %s match, too many to count. Check getResourceIds() for null first.',
                static::COUNT_MAX, $resource
            ));
        }
        return $ids;
    }

    /**
     * Count items per group, from the grouped counts when this request
     * already has them, otherwise a query per group.
     *
     * @param string $group A key of getGroupedCounts()
     * @param string $filter The item query parameter that selects one group
     */
    /**
     * Get the IDs of the item sets the caller may see, from the API, once per
     * request.
     *
     * @return array True, keyed by item set ID
     */
    protected function getVisibleItemSetIds(): array
    {
        return $this->cache['visible_item_sets'] ??= array_fill_keys(
            array_map('intval', $this->api->search('item_sets', [], ['returnScalar' => 'id'])->getContent()),
            true
        );
    }

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
     * Whether the caller can see a value of a property: in a value annotation
     * when $inAnnotation is true, and on any other resource when it's false.
     */
    protected function hasVisibleValue(int $propertyId, bool $inAnnotation): bool
    {
        return (bool) $this->entityManager->createQueryBuilder()
            ->select('x.id')
            ->from(Value::class, 'x')
            ->innerJoin('x.resource', 'r')
            ->where('x.property = :id')
            ->andWhere(sprintf('r %sINSTANCE OF %s', $inAnnotation ? '' : 'NOT ', ValueAnnotation::class))
            ->setParameter('id', $propertyId)
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Get what a value counts by: VALUE_TEXT, or its first characters.
     */
    protected function valueText(?int $prefixLength): string
    {
        return $prefixLength ? sprintf('SUBSTRING(%s, 1, %d)', self::VALUE_TEXT, $prefixLength) : self::VALUE_TEXT;
    }

    /**
     * Start a query over the values of the given resources, of one property
     * or of all, leaving out links to resources the caller can't see.
     */
    protected function createValueQuery(?int $propertyId, array $ids)
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Value::class, 'v')
            ->leftJoin('v.valueResource', 'vr')
            ->where('v.resource IN (:resources)')
            ->andWhere('(vr.id IS NOT NULL OR v.value IS NOT NULL OR v.uri IS NOT NULL)')
            ->setParameter('resources', $ids);
        if (null !== $propertyId) {
            $qb->andWhere('v.property = :property')->setParameter('property', $propertyId);
        }
        return $qb;
    }
}
