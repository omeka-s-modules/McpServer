<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Output\EmptyResultNotes;
use McpServer\Mcp\Query\ResourceCounter;
use McpServer\Mcp\Query\SearchFilters;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolException;
use McpServer\Mcp\Tool\ToolInterface;

/**
 * The base of count_values and count_properties, which count among the items,
 * media, or item sets, chosen with "resource_type", that match the same
 * filters as the searches.
 */
abstract class AbstractCountTool implements ToolInterface
{
    protected $counter;

    protected $searchFilters;

    protected $emptyResultNotes;

    public function __construct(ResourceCounter $counter, SearchFilters $searchFilters, EmptyResultNotes $emptyResultNotes)
    {
        $this->counter = $counter;
        $this->searchFilters = $searchFilters;
        $this->emptyResultNotes = $emptyResultNotes;
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    /**
     * Get the schema of "resource_type", which chooses what's counted.
     *
     * @param string $counted What the tool counts, such as "values"
     */
    protected function getResourceTypeSchema(string $counted): array
    {
        return [
            'resource_type' => [
                'type' => 'string',
                'enum' => SearchFilters::RESOURCES,
                'default' => 'items',
                'description' => sprintf('Whose %s to count: items, media, or item sets.', $counted),
            ],
        ];
    }

    /**
     * Read which resources to count, and get the IDs of those that match the
     * filters.
     *
     * @return array [the API resource name, the filters as Omeka's query, the
     *   IDs]
     * @throws ToolException When more match than ResourceCounter counts
     */
    protected function getMatches(ArgumentReader $reader): array
    {
        $resource = $reader->getEnum('resource_type', SearchFilters::RESOURCES, 'items');
        $filters = $this->searchFilters->buildFilters(
            $reader,
            $resource,
            array_fill_keys(array_keys(SearchFilters::FILTER_RESOURCES), '"resource_type" chooses what to count.')
        );
        $ids = $this->counter->getResourceIds($resource, $filters);
        if (null === $ids) {
            throw new ToolException(sprintf(
                'More than %s %s match, too many to count at once. Narrow them with filters, such as a class or a condition on a property.',
                number_format(ResourceCounter::COUNT_MAX), SearchFilters::noun($resource)
            ));
        }
        return [$resource, $filters, $ids];
    }
}
