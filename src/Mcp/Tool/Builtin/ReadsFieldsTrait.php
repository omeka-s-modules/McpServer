<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Mcp\Format\OutputLimits;
use McpServer\Mcp\Format\ResourceFormatter;
use McpServer\Mcp\Format\ValueFormatter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Lets get_item, get_item_set, and get_media read one field in full: a page of
 * values at a time, or one long text from an offset. This is how a model
 * reaches what a concise form cut, as OutputLimitNotes tells it.
 */
trait ReadsFieldsTrait
{
    /**
     * Get the formatter for whole resources, which reads an HTML media's
     * content.
     */
    abstract protected function getFormatter(): ResourceFormatter;

    /**
     * Get the formatter for values, which reads a field's values and texts.
     */
    abstract protected function getValueFormatter(): ValueFormatter;

    /**
     * Get the schema properties for reading one field.
     *
     * @param bool $content Whether "field" also takes "content", for a
     *   media's text
     */
    protected function getFieldSchema(bool $content = false): array
    {
        return [
            'field' => [
                'type' => 'string',
                'description' => sprintf(
                    'Read only this property\'s values, by term, all of them, %d at a time. Use it when a property has more values than the full form shows, or with "offset" to read a text the full form cut.%s',
                    OutputLimits::LIST_LIMIT,
                    $content ? ' "content" reads an HTML media\'s text the same way.' : ''
                ),
            ],
            'page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => OutputLimits::PAGE_MAX,
                'default' => 1,
                'description' => 'With "field", the page of values to return, starting at 1.',
            ],
            'position' => [
                'type' => 'integer',
                'minimum' => 1,
                'default' => 1,
                'description' => 'With "field" and "offset", which of the property\'s values to read, by its position among them, starting at 1.',
            ],
            'offset' => [
                'type' => 'integer',
                'minimum' => 0,
                'default' => 0,
                'description' => sprintf(
                    'With "field", read one value\'s text from this character, starting at 0, %s characters at a time.',
                    number_format(OutputLimits::CHUNK_LIMIT)
                ),
            ],
        ];
    }

    /**
     * Read one field's values, or one long text, when the "field" argument
     * asks for it.
     *
     * @param string $noun What one resource is called, such as "item set"
     * @return ToolResult|null Null when no field was asked for
     * @throws ToolException When the resource has no values for the field
     */
    protected function readField(AbstractResourceEntityRepresentation $resource, ArgumentReader $reader, string $noun): ?ToolResult
    {
        $term = $reader->getText('field');
        if (null === $term) {
            return null;
        }
        if ('content' === $term && $resource instanceof MediaRepresentation) {
            return $this->readContent($resource, $reader);
        }
        $total = count($resource->values()[$term]['values'] ?? []);
        if (!$total) {
            $terms = array_keys(array_filter($resource->values(), fn ($property) => $property['values']));
            throw new ToolException(sprintf('This %s has no values for "%s". Its fields are: %s.', $noun, $term, implode(', ', $terms)));
        }
        if ($reader->has('position') || $reader->has('offset')) {
            return $this->readValue($resource, $reader, $term, $total);
        }
        $page = $reader->getInt('page', 1, 1, OutputLimits::PAGE_MAX);
        $field = $this->getValueFormatter()->fieldPage($resource, $term, $page);
        $output = [
            'id' => $resource->id(),
            'title' => $resource->displayTitle(),
            'field' => $term,
            'page' => $page,
            'per_page' => OutputLimits::LIST_LIMIT,
        ] + $field;
        $notes = [];
        $pages = (int) ceil($total / OutputLimits::LIST_LIMIT);
        if ($page < $pages) {
            $notes[] = sprintf('There are %d values on %d pages. Call again with "page" %d for the next.', $total, $pages, $page + 1);
        }
        $notes[] = OutputLimitNotes::forFieldPage($term, $field['values'], ($page - 1) * OutputLimits::LIST_LIMIT + 1);
        $notes = array_filter($notes);
        return ToolResult::json($output, $notes ? implode(' ', $notes) : null);
    }

    /**
     * Read part of one value's text, from an offset.
     *
     * @param int $total How many values the resource has for the field
     */
    protected function readValue(AbstractResourceEntityRepresentation $resource, ArgumentReader $reader, string $term, int $total): ToolResult
    {
        $this->refusePage($reader);
        $position = $reader->getInt('position', 1, 1);
        if ($position > $total) {
            throw new ToolException(sprintf('%s has %d value%s here, so "position" can be at most %d.', $term, $total, 1 === $total ? '' : 's', $total));
        }
        $offset = $reader->getInt('offset', 0, 0);
        $chunk = $this->getValueFormatter()->valueChunk($resource, $term, $position, $offset);
        $output = [
            'id' => $resource->id(),
            'title' => $resource->displayTitle(),
            'field' => $term,
            'label' => $chunk['label'],
            'position' => $position,
            OutputLimits::TOTAL => $total,
        ];
        return $this->getChunkResult($output, $chunk, $offset);
    }

    /**
     * Read part of a media's text content, from an offset.
     */
    protected function readContent(MediaRepresentation $media, ArgumentReader $reader): ToolResult
    {
        $this->refusePage($reader);
        // A position of 1, the default, is allowed, since models often send
        // defaults.
        if (1 !== $reader->getInt('position', 1, 1)) {
            throw new ToolException('"content" is a single text, so it takes "offset" but not "position".');
        }
        $offset = $reader->getInt('offset', 0, 0);
        $chunk = $this->getFormatter()->contentChunk($media, $offset);
        if (null === $chunk) {
            throw new ToolException('This media has no text content. Only an HTML media holds its text itself, rather than in a file.');
        }
        $output = [
            'id' => $media->id(),
            'title' => $media->displayTitle(),
            'field' => 'content',
        ];
        return $this->getChunkResult($output, $chunk, $offset);
    }

    /**
     * Refuse "page" with "offset", unless it's 1. That's the default, which
     * models often send without meaning it.
     */
    protected function refusePage(ArgumentReader $reader): void
    {
        if (1 !== $reader->getInt('page', 1, 1, OutputLimits::PAGE_MAX)) {
            throw new ToolException('"page" pages through a field\'s values, and "offset" reads one text, so the two don\'t go together.');
        }
    }

    /**
     * Get the result of reading part of a text, with a note giving the next
     * offset when there's more.
     *
     * @param array $chunk ['length', 'text']
     */
    protected function getChunkResult(array $output, array $chunk, int $offset): ToolResult
    {
        $output += ['offset' => $offset, 'length' => $chunk['length'], 'text' => $chunk['text']];
        $end = $offset + mb_strlen($chunk['text']);
        $note = $end < $chunk['length']
            ? sprintf(
                'These are characters %s to %s of %s. Call again with "offset" %d for the next.',
                number_format($offset), number_format($end), number_format($chunk['length']), $end
            )
            : null;
        return ToolResult::json($output, $note);
    }
}
