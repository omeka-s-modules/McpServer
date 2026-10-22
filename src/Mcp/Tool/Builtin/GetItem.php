<?php
namespace McpServer\Mcp\Tool\Builtin;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class GetItem extends AbstractGetTool
{
    public function getTitle(): string
    {
        return 'Get item';
    }

    public function getDescription(): string
    {
        return 'Get one item in full by its id, as given by search_items: every metadata value with its label, '
            . 'its media, its item sets, a link to its public page, and in module_data whatever other modules '
            . 'add, such as map locations. The default response_format, "concise", suits almost every '
            . 'question. Use "detailed" only when you need '
            . 'Omeka\'s raw JSON-LD, which also lists everything that links to this one; it is longer. To look at '
            . 'one of its media, call get_media with the media\'s id.';
    }

    protected function getResourceName(): string
    {
        return 'items';
    }

    protected function getSingularNoun(): string
    {
        return 'item';
    }

    protected function getConcise(AbstractResourceEntityRepresentation $item): array
    {
        return $this->formatter->item($item);
    }
}
