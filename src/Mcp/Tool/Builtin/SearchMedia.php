<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Tool\ArgumentReader;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class SearchMedia extends AbstractSearchTool
{
    public function getTitle(): string
    {
        return 'Search media';
    }

    public function getDescription(): string
    {
        return 'Search and filter media, the files and other content attached to items, such as images, '
            . 'pages, and PDFs. Media can have their own metadata, such as a page\'s description or '
            . 'transcription. Returns a page of short summaries: id, title, class, description, media type, the '
            . 'item it belongs to, and links to the media\'s public page, its file, and the item\'s public page, '
            . 'plus the total number of matches. Call get_media with an id to see one, or name the properties you need '
            . 'in "properties" to see them for every result, such as each image\'s rights and creator.'
            . "\n\n"
            . 'Takes the same query, filters, and sorting as search_items, except item sets and media, which '
            . 'only items have, plus "media_type" and "item". get_overview lists the media types in use, with '
            . 'counts. With no query or filters, lists the most recently added media.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Only media of this item, by id, in the order they were added unless sorted otherwise.',
                ],
            ] + $this->getSearchSchema(),
        ];
    }

    protected function getToolQuery(ArgumentReader $reader): array
    {
        $item = $reader->getInt('item', null, 1, ArgumentReader::SAFE_INT);
        return null === $item ? [] : ['item_id' => $item];
    }

    protected function getDefaultSort(array $toolQuery): ?array
    {
        // An item's media in their order, as nearly as the API can sort: it
        // can't sort by position.
        return isset($toolQuery['item_id']) ? ['sort' => 'added', 'order' => 'asc'] : null;
    }

    protected function getResourceName(): string
    {
        return 'media';
    }

    protected function getPerPageDefault(): int
    {
        return 20;
    }

    protected function summarize(AbstractResourceEntityRepresentation $media): array
    {
        return $this->formatter->mediaSummary($media);
    }

    protected function getFilterAdvice(): array
    {
        $advice = 'Find the items with search_items, then list each one\'s media with "item".';
        return ['item_set' => $advice, 'has_media' => $advice] + parent::getFilterAdvice();
    }

    protected function getEmptyNote(array $query): string
    {
        if (isset($query['media_type'])) {
            return 'No media matched. Media types must match exactly; get_overview lists the ones in use.';
        }
        if (isset($query['item_id'])) {
            return 'No media matched: the item has no media, or isn\'t visible.';
        }
        return parent::getEmptyNote($query);
    }
}
