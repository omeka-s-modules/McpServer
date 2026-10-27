<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Format\JsonLdFormatter;
use McpServer\Mcp\Format\OutputLimits;
use McpServer\Mcp\Format\ResourceFormatter;
use McpServer\Mcp\Format\ValueFormatter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolException;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * The base of get_item, get_item_set, and get_media, which read one resource
 * the same way: in a concise form by default, or as Omeka's own JSON-LD on
 * request.
 *
 * Each can also read one property in full: a page of values at a time, or one
 * long text from an offset. This is how a model reaches what a concise form
 * cut, as OutputLimitNotes tells it.
 */
abstract class AbstractGetTool implements ToolInterface
{
    const FORMATS = ['concise', 'detailed'];

    protected $api;

    protected $formatter;

    protected $jsonLdFormatter;

    protected $valueFormatter;

    public function __construct(ApiManager $api, ResourceFormatter $formatter, ValueFormatter $valueFormatter, JsonLdFormatter $jsonLdFormatter)
    {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->valueFormatter = $valueFormatter;
        $this->jsonLdFormatter = $jsonLdFormatter;
    }

    /**
     * Get the API resource read, such as "items".
     */
    abstract protected function getResourceName(): string;

    /**
     * Get what one resource is called in messages, such as "item set".
     */
    abstract protected function getSingularNoun(): string;

    /**
     * Get the concise form of the resource.
     */
    abstract protected function getConcise(AbstractResourceEntityRepresentation $resource): array;

    /**
     * Get the schema of the arguments a tool takes besides the shared ones.
     */
    protected function getOwnSchema(): array
    {
        return [];
    }

    /**
     * Read something a tool has besides properties, such as a media's own
     * text, when its arguments ask for it, or null when they don't.
     */
    protected function readOwn(AbstractResourceEntityRepresentation $resource, ArgumentReader $reader): ?ToolResult
    {
        return null;
    }

    /**
     * Get an image to send with the resource, and a note when there's none to
     * send.
     *
     * @return array [JPEG bytes or null, note or null]
     */
    protected function getImage(AbstractResourceEntityRepresentation $resource, ArgumentReader $reader): array
    {
        return [null, null];
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => sprintf('The %s\'s id.', $this->getSingularNoun()),
                ],
                'response_format' => [
                    'type' => 'string',
                    'enum' => self::FORMATS,
                    'default' => 'concise',
                    'description' => '"concise" for readable values with labels, or "detailed" for Omeka\'s full JSON-LD.',
                ],
            ] + $this->getOwnSchema() + $this->getPropertyReadingSchema(),
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $reader = new ArgumentReader($arguments);
        $id = $reader->requireInt('id', 1);
        $format = $reader->getEnum('response_format', self::FORMATS, 'concise');
        try {
            $resource = $this->api->read($this->getResourceName(), $id)->getContent();
        } catch (NotFoundException $e) {
            throw new ToolException(sprintf('No %s with id %d exists or is visible to you.', $this->getSingularNoun(), $id));
        }
        $own = $this->readOwn($resource, $reader);
        if ($own) {
            return $own;
        }
        if ($reader->has('property')) {
            if ('detailed' === $format) {
                throw new ToolException('"property" reads readable values, so it doesn\'t take "response_format" "detailed".');
            }
            return $this->readProperty($resource, $reader);
        }
        [$image, $imageNote] = $this->getImage($resource, $reader);
        if ('detailed' !== $format) {
            $concise = $this->getConcise($resource);
            $notes = array_filter([OutputLimitNotes::forConcise($concise), $imageNote]);
            $result = ToolResult::json($concise, $notes ? implode(' ', $notes) : null);
        } else {
            [$jsonLd, $cut, $textsCut] = $this->jsonLdFormatter->jsonLd($resource);
            $url = $this->formatter->publicUrl($resource);
            $notes = array_filter([$url ? sprintf('Public page: %s', $url) : null, OutputLimitNotes::forJsonLd($resource, $cut, $textsCut), $imageNote]);
            $result = ToolResult::json($jsonLd, $notes ? implode("\n", $notes) : null);
        }
        return null === $image ? $result : $result->withImage($image, 'image/jpeg');
    }

    /**
     * Get the schema of the arguments for reading one property.
     */
    protected function getPropertyReadingSchema(): array
    {
        return [
            'property' => [
                'type' => 'string',
                'description' => sprintf(
                    'Read only this property\'s values, by term, all of them, %d at a time. Use it when a property has more values than the full form shows, or with "offset" to read a text the full form cut.',
                    OutputLimits::LIST_LIMIT
                ),
            ],
            'page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => OutputLimits::PAGE_MAX,
                'default' => 1,
                'description' => 'With "property", the page of values to return, starting at 1.',
            ],
            'position' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'With "property" and "offset", which of the property\'s values to read, by its position among them, starting at 1.',
            ],
            'offset' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => sprintf(
                    'With "property", read one value\'s text from this character, starting at 0, %s characters at a time.',
                    number_format(OutputLimits::CHUNK_LIMIT)
                ),
            ],
        ];
    }

    /**
     * Read one property's values, or one long text.
     *
     * @throws ToolException When the resource has no values for the property
     */
    protected function readProperty(AbstractResourceEntityRepresentation $resource, ArgumentReader $reader): ToolResult
    {
        $term = $reader->getText('property');
        $total = count($resource->values()[$term]['values'] ?? []);
        if (!$total) {
            $terms = array_keys(array_filter($resource->values(), fn ($property) => $property['values']));
            throw new ToolException(sprintf('This %s has no values for "%s". Its properties are: %s.', $this->getSingularNoun(), $term, implode(', ', $terms)));
        }
        // "offset", or a "position" past the first, reads one value's text. A
        // "position" of 1 alone is the default sent explicitly, and pages.
        if ($reader->has('offset') || 1 !== $reader->getInt('position', 1, 1)) {
            return $this->readValue($resource, $reader, $term, $total);
        }
        $page = $reader->getInt('page', 1, 1, OutputLimits::PAGE_MAX);
        $values = $this->valueFormatter->propertyPage($resource, $term, $page);
        $output = [
            'id' => $resource->id(),
            'title' => $resource->displayTitle(),
            'property' => $term,
            'page' => $page,
            'per_page' => OutputLimits::LIST_LIMIT,
        ] + $values;
        $notes = [];
        $pages = (int) ceil($total / OutputLimits::LIST_LIMIT);
        if ($page < $pages) {
            $notes[] = sprintf('There are %d values on %d pages. Call again with "page" %d for the next.', $total, $pages, $page + 1);
        }
        $notes[] = OutputLimitNotes::forPropertyPage($term, $values['values'], ($page - 1) * OutputLimits::LIST_LIMIT + 1);
        $notes = array_filter($notes);
        return ToolResult::json($output, $notes ? implode(' ', $notes) : null);
    }

    /**
     * Read part of one value's text, from an offset.
     *
     * @param int $total How many values the resource has for the property
     */
    protected function readValue(AbstractResourceEntityRepresentation $resource, ArgumentReader $reader, string $term, int $total): ToolResult
    {
        $this->refusePage($reader);
        $position = $reader->getInt('position', 1, 1);
        if ($position > $total) {
            throw new ToolException(sprintf('%s has %d value%s here, so "position" can be at most %d.', $term, $total, 1 === $total ? '' : 's', $total));
        }
        $offset = $reader->getInt('offset', 0, 0);
        $chunk = $this->valueFormatter->valueChunk($resource, $term, $position, $offset);
        $output = [
            'id' => $resource->id(),
            'title' => $resource->displayTitle(),
            'property' => $term,
            'label' => $chunk['label'],
            'position' => $position,
            OutputLimits::TOTAL => $total,
        ];
        // Reading one value of several, perhaps only by sending defaults.
        $lead = $total > 1
            ? sprintf('This is value %d of the %d of %s. Leave out "offset" and "position" to list them all.', $position, $total, $term)
            : null;
        return $this->getChunkResult($output, $chunk, $offset, $lead);
    }

    /**
     * Refuse "page" with "offset", unless it's 1. That's the default, which
     * models often send without meaning it.
     */
    protected function refusePage(ArgumentReader $reader): void
    {
        if (1 !== $reader->getInt('page', 1, 1, OutputLimits::PAGE_MAX)) {
            throw new ToolException('"page" pages through a property\'s values, and "offset" reads one text, so the two don\'t go together.');
        }
    }

    /**
     * Get the result of reading part of a text, with a note giving the next
     * offset when there's more.
     *
     * @param array $chunk ['length', 'text']
     * @param string|null $lead A note to put first
     */
    protected function getChunkResult(array $output, array $chunk, int $offset, ?string $lead = null): ToolResult
    {
        $output += ['offset' => $offset, 'length' => $chunk['length'], 'text' => $chunk['text']];
        $end = $offset + mb_strlen($chunk['text']);
        $notes = array_filter([$lead, $end < $chunk['length']
            ? sprintf(
                'These are characters %s to %s of %s. Call again with "offset" %d for the next.',
                number_format($offset), number_format($end), number_format($chunk['length']), $end
            )
            : null]);
        return ToolResult::json($output, $notes ? implode(' ', $notes) : null);
    }
}
