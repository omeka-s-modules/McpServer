<?php
namespace McpServer\Stdlib;

use McpServer\Mcp\Exception\ToolException;
use Omeka\Api\Manager as ApiManager;

/**
 * What search can filter on, as the caller may see it.
 *
 * Shared by the search tools, which resolve terms, labels, and slugs here, and
 * by get_overview, which lists them with counts from ResourceCounter.
 * Everything comes from the API, or from ResourceCounter: its counts, limited
 * to what the API returned, and its checks that a term is in use, which
 * answer what core's "used" filter answers. Each list is fetched once per
 * request.
 */
class SearchOptions
{
    // Most entries an overview list returns; its total says when there are more.
    const OVERVIEW_LIMIT = 200;

    // Most classes an error lists, so a model can correct a class name.
    const CLASSES_IN_ERROR_MAX = 50;

    protected $api;

    protected $counter;

    protected $cache = [];

    public function __construct(ApiManager $api, ResourceCounter $counter)
    {
        $this->api = $api;
        $this->counter = $counter;
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
                'per_page' => self::OVERVIEW_LIMIT,
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
     * Get the classes resources use, by term, without counts.
     *
     * For listing: findClass() resolves a name with targeted queries
     * instead, and lists these only when nothing matches.
     *
     * @return array List of ['id', 'term', 'label']
     */
    public function getClasses(): array
    {
        return $this->getUsedTerms('resource_classes');
    }

    /**
     * Get the classes items use, most used first, with their item counts.
     *
     * @return array List of ['id', 'term', 'label', 'items']
     */
    public function getClassCounts(): array
    {
        return $this->cache['class_counts'] ??= (function () {
            $this->counter->getGroupedCounts();
            $counts = $this->counter->countItemsByClass(array_column($this->getClasses(), 'id'));
            $classes = [];
            foreach ($this->getClasses() as $class) {
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
        return $this->getUsedTerms('properties');
    }

    /**
     * Get the classes or properties in use, by term.
     *
     * Checks each term by ID, a query each, which takes about 0.1 s for a
     * thousand terms, rather than core's "used" filter, which joins every
     * value or resource. Only the terms in use are then loaded.
     *
     * @param string $resource "resource_classes" or "properties"
     * @return array List of ['id', 'term', 'label']
     */
    protected function getUsedTerms(string $resource): array
    {
        return $this->cache[$resource] ??= (function () use ($resource) {
            $ids = array_values(array_filter(
                array_keys($this->api->search($resource, [], ['returnScalar' => 'id'])->getContent()),
                fn ($id) => $this->counter->isUsed($resource, (int) $id)
            ));
            $terms = $ids ? array_map(fn ($term) => $this->toRow($term), $this->api->search($resource, ['id' => $ids])->getContent()) : [];
            usort($terms, fn ($a, $b) => strcmp($a['term'], $b['term']));
            return $terms;
        })();
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

    /**
     * Get the slugs of the sites the caller may see, by site ID.
     *
     * Lighter than getSites(), which loads whole site records.
     */
    public function getSiteSlugs(): array
    {
        return $this->cache['site_slugs'] ??= $this->api->search('sites', [], ['returnScalar' => 'slug'])->getContent();
    }

    /**
     * Find the class a term or label names, among the classes in use.
     *
     * @throws ToolException When nothing matches, or a label matches several
     */
    public function findClass(string $termOrLabel): int
    {
        if (str_contains($termOrLabel, ':')) {
            $matches = array_filter([$this->findUsedTerm('resource_classes', $termOrLabel)]);
        } else {
            // Core can't search classes by label, so a label is matched
            // against every class's label alone, and only the matches are
            // checked for use.
            $ids = array_filter(
                array_keys(array_filter($this->getClassLabels(), fn ($label) => 0 === strcasecmp((string) $label, $termOrLabel))),
                fn ($id) => $this->counter->isUsed('resource_classes', (int) $id)
            );
            $matches = $ids ? array_map(
                fn ($class) => $this->toRow($class),
                $this->api->search('resource_classes', ['id' => array_values($ids)], ['countQuery' => false])->getContent()
            ) : [];
        }
        if (1 === count($matches)) {
            return reset($matches)['id'];
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
        $property = $this->findUsedTerm('properties', $term);
        if (!$property) {
            throw new ToolException(sprintf('No values use the property "%s". Call get_overview to see the properties in use.', $term));
        }
        return $property['id'];
    }

    /**
     * Get the terms, among the properties this request resolved, whose values
     * are all in value annotations, which "where" can't search.
     *
     * @param int[] $propertyIds
     * @return string[]
     */
    public function getAnnotationOnlyTerms(array $propertyIds): array
    {
        $terms = [];
        foreach (array_filter($this->cache['used_terms']['properties'] ?? []) as $property) {
            if (in_array($property['id'], $propertyIds) && $this->counter->isUsedOnlyInAnnotations($property['id'])) {
                $terms[] = $property['term'];
            }
        }
        return $terms;
    }

    /**
     * Find the site a slug names.
     *
     * @throws ToolException When no visible site has the slug
     */
    public function findSite(string $slug): int
    {
        $siteId = array_search($slug, $this->getSiteSlugs(), true);
        if (false === $siteId) {
            throw new ToolException(sprintf('There is no site "%s". Call get_overview to see the sites.', $slug));
        }
        return (int) $siteId;
    }

    /**
     * Find a class or property in use by its term, once per request.
     *
     * Looks the term up, then checks it's in use, rather than asking core's
     * "used" filter, which joins every value of a property before returning
     * the one row.
     *
     * @param string $resource "resource_classes" or "properties"
     * @return array|null ['id', 'term', 'label']
     */
    protected function findUsedTerm(string $resource, string $term): ?array
    {
        if (!array_key_exists($term, $this->cache['used_terms'][$resource] ?? [])) {
            $found = $this->api->search($resource, ['term' => $term, 'limit' => 1], ['countQuery' => false])->getContent();
            $this->cache['used_terms'][$resource][$term] = $found && $this->counter->isUsed($resource, $found[0]->id())
                ? $this->toRow($found[0])
                : null;
        }
        return $this->cache['used_terms'][$resource][$term];
    }

    /**
     * Get the label of every class, in use or not, by class ID.
     *
     * A scalar search, since a vocabulary such as schema.org has hundreds of
     * classes.
     */
    protected function getClassLabels(): array
    {
        return $this->cache['class_labels'] ??= $this->api->search('resource_classes', [], ['returnScalar' => 'label'])->getContent();
    }

    /**
     * Get a class or property as a row of a list.
     *
     * @param \Omeka\Api\Representation\ResourceClassRepresentation|\Omeka\Api\Representation\PropertyRepresentation $term
     * @return array ['id', 'term', 'label']
     */
    protected function toRow($term): array
    {
        return ['id' => $term->id(), 'term' => $term->term(), 'label' => $term->label()];
    }
}
