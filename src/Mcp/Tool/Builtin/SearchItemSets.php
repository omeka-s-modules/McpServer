<?php
namespace McpServer\Mcp\Tool\Builtin;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class SearchItemSets extends AbstractSearchTool
{
    public function getTitle(): string
    {
        return 'Search item sets';
    }

    public function getDescription(): string
    {
        return 'Search and filter item sets, the groups items belong to (called collections in Omeka Classic), by their title, '
            . 'description, and other metadata. Returns a page of short summaries: id, title, description, how '
            . 'many items the set has, and a link to its public page, plus the total number of matches. Call '
            . 'get_item_set with an id to read one in full, and search_items with "item_set" to list its items.'
            . "\n\n"
            . 'get_overview already lists up to 200 item sets with their item counts. Use this to search them by '
            . 'what they\'re about, or when there are more. Takes the same query, filters, and sorting as '
            . 'search_items, except item sets and media, which only items have.';
    }

    protected function getFilterAdvice(): array
    {
        $advice = 'To find the items in a set, call search_items with "item_set".';
        return ['item_set' => $advice, 'has_media' => $advice] + parent::getFilterAdvice();
    }

    protected function getResourceName(): string
    {
        return 'item_sets';
    }

    protected function getPerPageDefault(): int
    {
        return 20;
    }

    protected function summarize(AbstractResourceEntityRepresentation $itemSet): array
    {
        return $this->formatter->itemSetSummary($itemSet);
    }
}
