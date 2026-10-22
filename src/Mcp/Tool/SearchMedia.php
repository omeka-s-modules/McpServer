<?php
namespace McpServer\Mcp\Tool;

use McpServer\Mcp\Exception\ToolException;
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
            . 'transcription. Returns a page of short summaries: id, title, media type, the item it belongs to, '
            . 'and links to its file and the item\'s public page, plus the total number of matches. Call '
            . 'get_media with an id to see one.'
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
                'media_type' => [
                    'type' => 'string',
                    'description' => 'Only media of this exact type, e.g. "application/pdf" or "image/jpeg".',
                ],
                'item' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Only media of this item, by id, in the order they were added unless sorted otherwise.',
                ],
            ] + $this->getSearchSchema(),
        ];
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $this->refuseItemFilters($arguments);
        $reader = new ArgumentReader($arguments);
        $query = [];
        $mediaType = trim((string) $reader->getString('media_type'));
        if ('0' === $mediaType) {
            // Core skips a media type of "0" as if it were empty.
            throw new ToolException('"0" isn\'t a media type. Media types look like "application/pdf"; get_overview lists the ones in use.');
        }
        if ('' !== $mediaType) {
            $query['media_type'] = $mediaType;
        }
        if (null !== ($item = $reader->getInt('item', null, 1, ArgumentReader::SAFE_INT))) {
            $query['item_id'] = $item;
            // Blank counts as absent, as it does in the query builder.
            if ('' === trim((string) $reader->getString('sort')) && '' === trim((string) $reader->getString('query'))) {
                // An item's media in their order, as nearly as the API can
                // sort: it can't sort by position.
                $defaults = ['sort' => 'added'];
                if (null === $reader->getEnum('order', ['asc', 'desc'])) {
                    $defaults['order'] = 'asc';
                }
                $reader = new ArgumentReader(array_merge($arguments, $defaults));
            }
        }
        return ToolResult::json($this->search($reader, $query));
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

    protected function getItemFilterAdvice(): string
    {
        return 'Find the items with search_items, then list each one\'s media with "item".';
    }

    protected function getEmptyHint(array $query): string
    {
        if (isset($query['media_type'])) {
            return 'No media matched. Media types must match exactly; get_overview lists the ones in use.';
        }
        if (isset($query['item_id'])) {
            return 'No media matched: the item has no media, or isn\'t visible.';
        }
        return parent::getEmptyHint($query);
    }
}
