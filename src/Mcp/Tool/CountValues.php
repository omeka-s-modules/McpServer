<?php
namespace McpServer\Mcp\Tool;

use Doctrine\ORM\EntityManager;
use McpServer\Mcp\Exception\ToolException;
use McpServer\Stdlib\ItemFormatter;
use McpServer\Stdlib\ResourceQueryBuilder;
use McpServer\Stdlib\SearchOptions;
use Omeka\Entity\Value;

/**
 * Counts the values of one field across the items, media, or item sets that
 * match the filters.
 *
 * Omeka's API can't group, so this tool counts with Doctrine, under the rule
 * in ToolInterface: the API returns the IDs of the matching resources,
 * applying every filter, module extension, and visibility rule, and the
 * grouped query reads only the values of those resources. Core's Doctrine filters then hide
 * private values, and the titles of linked resources the caller can't see. A
 * linked media counts by its title even when its item is private, as core's
 * own pages show it.
 *
 * Values group under the database's collation, so values that differ only in
 * case, accents, or trailing spaces count as one. MySQL also compares only the
 * first max_sort_length bytes (1,024 by default) of long text, so very long
 * values that start alike may count as one.
 */
class CountValues implements ToolInterface
{
    const LIMIT_DEFAULT = 20;

    const LIMIT_MAX = 100;

    const RESOURCE_TYPES = ['items', 'media', 'item_sets'];

    // Longest value shown, since a field may hold long text.
    const VALUE_LIMIT = 300;

    // What a value counts by: its text, its linked resource's title, or its
    // URI, or "" for a visible linked resource with no title.
    const LABEL = "COALESCE(vr.title, v.value, v.uri, '')";

    protected $entityManager;

    protected $queryBuilder;

    protected $options;

    protected $formatter;

    public function __construct(EntityManager $entityManager, ResourceQueryBuilder $queryBuilder, SearchOptions $options, ItemFormatter $formatter)
    {
        $this->entityManager = $entityManager;
        $this->queryBuilder = $queryBuilder;
        $this->options = $options;
        $this->formatter = $formatter;
    }

    public function getTitle(): string
    {
        return 'Count values';
    }

    public function getDescription(): string
    {
        return 'Count the values of one field: which values are most common, and how many items have each. Use '
            . 'it for questions such as the most frequent creators, or the most common subjects among paintings, '
            . 'instead of paging through search results. Takes the same filters as search_items, to count within '
            . 'a subset. A value that links to another item counts by that item\'s title. Call get_overview first '
            . 'to see the properties in use.'
            . "\n\n"
            . 'Counts items by default. Media and item sets have metadata of their own, such as the rights and '
            . 'credits of each image: set "resource_type" to count theirs. Counting media also takes '
            . '"media_type", such as "image/jpeg". Item sets and media presence filter only items.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'field' => [
                    'type' => 'string',
                    'description' => 'The property to count, by term, e.g. "dcterms:subject".',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::LIMIT_MAX,
                    'default' => self::LIMIT_DEFAULT,
                    'description' => sprintf('How many of the most common values to return, from 1 to %d.', self::LIMIT_MAX),
                ],
                'resource_type' => [
                    'type' => 'string',
                    'enum' => self::RESOURCE_TYPES,
                    'default' => 'items',
                    'description' => 'Whose values to count: items, media, or item sets.',
                ],
                'media_type' => [
                    'type' => 'string',
                    'description' => 'When counting media, only media of this exact type, e.g. "image/jpeg".',
                ],
            ] + $this->queryBuilder->getFilterSchema('items'),
            'required' => ['field'],
        ];
    }

    public function getAnnotations(): array
    {
        return Annotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $reader = new ArgumentReader($arguments);
        $field = trim((string) $reader->getString('field'));
        if ('' === $field) {
            throw new ToolException('"field" is needed: a property term such as "dcterms:subject".');
        }
        $propertyId = $this->options->findProperty($field);
        $limit = $reader->getInt('limit', self::LIMIT_DEFAULT, 1, self::LIMIT_MAX);
        $resource = $reader->getEnum('resource_type', self::RESOURCE_TYPES, 'items');
        // Named for the resource, such as "item sets", in output and messages.
        $label = str_replace('_', ' ', $resource);
        if ('items' !== $resource) {
            foreach (ResourceQueryBuilder::ITEM_FILTERS as $name) {
                if (array_key_exists($name, $arguments)) {
                    throw new ToolException(sprintf('Counting %s doesn\'t take "%s", which only items have.', $label, $name));
                }
            }
        }
        $filters = $this->queryBuilder->buildFilters($reader, $resource);
        $mediaType = trim((string) $reader->getString('media_type'));
        if ('' !== $mediaType) {
            if ('media' !== $resource) {
                throw new ToolException('"media_type" applies only when "resource_type" is "media".');
            }
            if ('0' === $mediaType) {
                // Core skips a media type of "0" as if it were empty.
                throw new ToolException('"0" isn\'t a media type. Media types look like "image/jpeg"; get_overview lists the ones in use.');
            }
            $filters['media_type'] = $mediaType;
        }
        $ids = $this->options->getResourceIds($resource, $filters);
        if (null === $ids) {
            throw new ToolException(sprintf(
                'More than %d %s match, too many to count at once. Narrow them with filters, such as a class or a condition on a field.',
                SearchOptions::COUNT_MAX, $label
            ));
        }
        $values = [];
        $totals = ['resources' => 0, 'values' => 0];
        if ($ids) {
            foreach ($this->countValues($propertyId, $ids, $limit) as $row) {
                $values[] = [
                    // A linked resource with no title shows as get_item shows it.
                    'value' => '' === $row['label'] ? '[Untitled]' : $this->formatter->shorten($row['label'], self::VALUE_LIMIT),
                    $resource => (int) $row['resources'],
                ];
            }
            $totals = $this->countTotals($propertyId, $ids);
        }
        $output = [
            'field' => $field,
            'matching_' . $resource => count($ids),
            $resource . '_with_field' => $totals['resources'],
            'distinct_values' => $totals['values'],
            'values' => $values,
        ];
        if (!$ids) {
            $output['hint'] = sprintf('No %s matched the filters. Loosen them, or check them against get_overview.', $label);
        } elseif (!$totals['resources']) {
            $output['hint'] = sprintf('None of the matching %s has a value for %s.', $label, $field);
            if ('items' === $resource) {
                $output['hint'] .= ' Media and item sets have values of their own: set "resource_type" to count theirs.';
            }
        }
        return ToolResult::json($output);
    }

    /**
     * Get the most common values of a property among the given resources,
     * with how many resources have each.
     *
     * The database sorts and limits, so a field with many distinct values,
     * such as titles, never loads them all. A value counts by its text, or its
     * linked resource's title, or its URI. A link to a resource the caller
     * can't see has none of these, so it doesn't count. A link to a visible
     * resource with no title has an empty label.
     *
     * @return array List of ['label', 'resources']
     */
    protected function countValues(int $propertyId, array $ids, int $limit): array
    {
        return $this->createValueQuery($propertyId, $ids)
            ->select(self::LABEL . ' AS label', 'COUNT(DISTINCT v.resource) AS resources')
            ->groupBy('label')
            ->orderBy('resources', 'DESC')
            ->addOrderBy('label', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Count the given resources that have a value of a property, and the
     * distinct values they have.
     *
     * @return array ['resources' => int, 'values' => int]
     */
    protected function countTotals(int $propertyId, array $ids): array
    {
        $row = $this->createValueQuery($propertyId, $ids)
            ->select('COUNT(DISTINCT v.resource) AS resources', 'COUNT(DISTINCT ' . self::LABEL . ') AS distinct_values')
            ->getQuery()
            ->getSingleResult();
        return ['resources' => (int) $row['resources'], 'values' => (int) $row['distinct_values']];
    }

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
