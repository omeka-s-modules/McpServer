<?php
namespace McpServer\Mcp\Query;

use Omeka\Api\Manager as ApiManager;

/**
 * Looks up the classes, properties, and sites a model names, and lists the
 * classes and properties in use, limited to what the caller can see.
 *
 * It answers with plain data, a row or a list, or null when nothing matches,
 * and SearchFilters decides what to tell the model then. The checks that a
 * class or property is in use come from ResourceCounter, and follow core's
 * "used" filter, so they can count a value on a private item. Everything
 * else comes from the API. Each lookup is made once per request.
 */
class NameLookup
{
    protected $api;

    protected $counter;

    protected $cache = [];

    public function __construct(ApiManager $api, ResourceCounter $counter)
    {
        $this->api = $api;
        $this->counter = $counter;
    }

    /**
     * Get the classes resources use, by term, without counts.
     *
     * For listing, as GetOverview does with item counts, and SearchFilters
     * does when a class name matches nothing. findUsedTerm() and
     * findUsedClassesByLabel() resolve one name with targeted queries instead.
     *
     * @return array List of ['id', 'term', 'label']
     */
    public function getUsedClasses(): array
    {
        return $this->getUsedTerms('resource_classes');
    }

    /**
     * Get the properties in use, by term.
     *
     * @return array List of ['id', 'term', 'label']
     */
    public function getUsedProperties(): array
    {
        return $this->getUsedTerms('properties');
    }

    /**
     * Get the slugs of the sites the caller can see, by site ID.
     *
     * Lighter than get_overview's site list, which loads whole site records.
     */
    public function getSiteSlugs(): array
    {
        return $this->cache['site_slugs'] ??= $this->api->search('sites', [], ['returnScalar' => 'slug'])->getContent();
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
    public function findUsedTerm(string $resource, string $term): ?array
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
     * Find the classes in use whose label is the given one, ignoring case.
     *
     * Core can't search classes by label, so the label is matched against
     * every class's label alone, and only the matches are checked for use.
     *
     * @return array List of ['id', 'term', 'label'], empty when none matches
     */
    public function findUsedClassesByLabel(string $label): array
    {
        $ids = array_filter(
            array_keys(array_filter($this->getClassLabels(), fn ($classLabel) => mb_strtolower((string) $classLabel) === mb_strtolower($label))),
            fn ($id) => $this->counter->isUsed('resource_classes', (int) $id)
        );
        return $ids ? array_map(
            fn ($class) => $this->toRow($class),
            $this->api->search('resource_classes', ['id' => array_values($ids)], ['countQuery' => false])->getContent()
        ) : [];
    }

    /**
     * Get the terms of the given properties whose values are all in value
     * annotations, which "where" can't search.
     *
     * @param int[] $propertyIds
     * @return string[] In the order the properties are given
     */
    public function getAnnotationOnlyTerms(array $propertyIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $propertyIds)));
        return $this->cache['annotation_only_terms'][implode(',', $ids)] ??= (function () use ($ids) {
            $ids = array_values(array_filter($ids, fn ($id) => $this->counter->isUsedOnlyInAnnotations($id)));
            if (!$ids) {
                return [];
            }
            $terms = [];
            foreach ($this->api->search('properties', ['id' => $ids], ['countQuery' => false])->getContent() as $property) {
                $terms[$property->id()] = $property->term();
            }
            return array_values(array_filter(array_map(fn ($id) => $terms[$id] ?? null, $ids)));
        })();
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
