<?php
namespace McpServer\Mcp\Tool\Builtin;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class GetItemSet extends AbstractGetTool
{
    public function getTitle(): string
    {
        return 'Get item set';
    }

    public function getDescription(): string
    {
        return 'Get one item set in full by its id, as given by get_overview or search_item_sets: its title, '
            . 'description, and other metadata, how many items it has, a link to its public page, and in '
            . 'module_data whatever other modules add. To list its items, call search_items with "item_set". '
            . 'The default response_format, "concise", suits almost every question. Use "detailed" only when '
            . 'you need Omeka\'s raw JSON-LD.';
    }

    protected function getResourceName(): string
    {
        return 'item_sets';
    }

    protected function getSingularNoun(): string
    {
        return 'item set';
    }

    protected function getConcise(AbstractResourceEntityRepresentation $itemSet): array
    {
        return $this->formatter->itemSet($itemSet);
    }
}
