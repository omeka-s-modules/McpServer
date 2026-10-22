<?php
namespace McpServer\Mcp\Tool\Builtin;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class SearchItems extends AbstractSearchTool
{
    public function getTitle(): string
    {
        return 'Search items';
    }

    public function getDescription(): string
    {
        return 'Search and filter the collection\'s items. Returns a page of short summaries: id, title, '
            . 'class, description, date, creator, and a link to the item\'s public page, plus the total number '
            . 'of matches. A summary\'s date is the first of dcterms:date, dcterms:created, or dcterms:issued '
            . 'that the item has, so check which field an item uses before sorting on it. Call get_item with an '
            . 'id to read an item in full.'
            . "\n\n"
            . '"query" is full-text search, most relevant first. It matches whole words only, so "letter" '
            . 'doesn\'t find "letters"; use "contains" to match part of a word, such as a plural. Filters narrow '
            . 'the results, alone or with a query: by item set, class, site, media, or conditions on fields. Call '
            . 'get_overview first to see the item sets, classes, properties, and sites you can filter on. '
            . 'With no query or filters, lists the most recently added items.';
    }

    protected function getResourceName(): string
    {
        return 'items';
    }

    protected function getPerPageDefault(): int
    {
        return 10;
    }

    protected function summarize(AbstractResourceEntityRepresentation $item): array
    {
        return $this->formatter->itemSummary($item);
    }
}
