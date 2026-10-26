<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Mcp\Query\NameLookup;
use McpServer\Mcp\Query\ResourceCounter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;

/**
 * Counts which fields the items, media, or item sets that match the filters
 * have, and how many have each.
 *
 * The counting is ResourceCounter's, under the rule in ToolInterface, as for
 * count_values: the API returns the IDs of the matching resources, applying
 * every filter, module extension, and visibility rule, and the grouped query
 * reads only their values. Core's Doctrine filters then hide private values,
 * and a link to a resource the caller can't see doesn't count.
 */
class CountFields implements ToolInterface
{
    protected $searchFilters;

    protected $counter;

    protected $names;

    public function __construct(SearchFilters $searchFilters, ResourceCounter $counter, NameLookup $names)
    {
        $this->searchFilters = $searchFilters;
        $this->counter = $counter;
        $this->names = $names;
    }

    public function getTitle(): string
    {
        return 'Count fields';
    }

    public function getDescription(): string
    {
        return 'Count which fields are filled in: for the items that match the filters, how many have each '
            . 'field. Use it to see what metadata a set of records has, or to audit it for gaps, in one call '
            . 'rather than counting one field at a time with count_values. Takes the same filters as '
            . 'search_items, to look within a subset such as one class or item set.'
            . "\n\n"
            . 'Counts items by default. Media and item sets have metadata of their own: set "resource_type" to '
            . 'count theirs. Lists every field at least one matching record has, most common first. Where values '
            . 'carry language tags, a field also lists how many have it in each language, to see how far '
            . 'translations go. A field none has isn\'t listed; to find the records missing a field, search '
            . 'with a "where" condition with "missing".';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'resource_type' => [
                    'type' => 'string',
                    'enum' => SearchFilters::RESOURCES,
                    'default' => 'items',
                    'description' => 'Whose fields to count: items, media, or item sets.',
                ],
            ] + $this->searchFilters->getFilterSchema(null),
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $reader = new ArgumentReader($arguments);
        $resource = $reader->getEnum('resource_type', SearchFilters::RESOURCES, 'items');
        // What the resources are called in messages, such as "item sets".
        $noun = $this->searchFilters->noun($resource);
        $filters = $this->searchFilters->buildFilters(
            $reader,
            $resource,
            array_fill_keys(array_keys(SearchFilters::FILTER_RESOURCES), '"resource_type" chooses what to count.')
        );
        $ids = $this->counter->getResourceIds($resource, $filters);
        if (null === $ids) {
            throw new ToolException(sprintf(
                'More than %s %s match, too many to count at once. Narrow them with filters, such as a class or a condition on a field.',
                number_format(ResourceCounter::COUNT_MAX), $noun
            ));
        }
        $properties = array_column($this->names->getUsedProperties(), null, 'id');
        // How many have each field in each language, most first.
        $languages = [];
        foreach ($this->counter->countFieldLanguages($ids) as $row) {
            $languages[$row['property']][$row['lang']] = (int) $row['resources'];
        }
        $fields = [];
        foreach ($this->counter->countFields($ids) as $row) {
            $property = $properties[$row['property']] ?? null;
            if ($property) {
                $fields[] = ['field' => $property['term'], 'label' => $property['label'], $resource => (int) $row['resources']]
                    + (isset($languages[$row['property']]) ? ['languages' => $languages[$row['property']]] : []);
            }
        }
        // Most common first, then by term, so the order is stable.
        usort($fields, fn ($a, $b) => [$b[$resource], $a['field']] <=> [$a[$resource], $b['field']]);
        $output = [
            'matching_' . $resource => count($ids),
            'fields' => $fields,
        ];
        $note = null;
        if (!$ids) {
            $note = $this->searchFilters->explainEmpty($filters, $noun);
        } elseif (!$fields) {
            $note = sprintf('None of the matching %s has a value you can see.', $noun);
        }
        return ToolResult::json($output, $note);
    }
}
