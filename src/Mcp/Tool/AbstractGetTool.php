<?php
namespace McpServer\Mcp\Tool;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Stdlib\ResourceFormatter;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * What get_item and get_item_set share, so the two read a resource the same
 * way: a concise form by default, or Omeka's own JSON-LD on request.
 */
abstract class AbstractGetTool implements ToolInterface
{
    use ReadsFieldsTrait;

    const FORMATS = ['concise', 'detailed'];

    protected $api;

    protected $formatter;

    public function __construct(ApiManager $api, ResourceFormatter $formatter)
    {
        $this->api = $api;
        $this->formatter = $formatter;
    }

    /**
     * Get the API resource read, "items" or "item_sets".
     */
    abstract protected function getResourceName(): string;

    /**
     * Get what one resource is called in messages, such as "item set".
     */
    abstract protected function getSingularLabel(): string;

    /**
     * Get the concise form of the resource.
     */
    abstract protected function getConcise(AbstractResourceEntityRepresentation $resource): array;

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => sprintf('The %s\'s id.', $this->getSingularLabel()),
                ],
                'response_format' => [
                    'type' => 'string',
                    'enum' => self::FORMATS,
                    'default' => 'concise',
                    'description' => '"concise" for readable values with labels, or "detailed" for Omeka\'s full JSON-LD.',
                ],
            ] + $this->getFieldSchema(),
            'required' => ['id'],
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
            throw new ToolException(sprintf('No %s with id %d exists or is visible to you.', $this->getSingularLabel(), $id));
        }
        if ($reader->has('field')) {
            if ('detailed' === $format) {
                throw new ToolException('"field" reads readable values, so it doesn\'t take "response_format" "detailed".');
            }
            return $this->readField($resource, $reader, $this->getSingularLabel());
        }
        if ('detailed' !== $format) {
            $concise = $this->getConcise($resource);
            return ToolResult::json($concise, $this->getCutNote($concise));
        }
        [$jsonLd, $cut, $textsCut] = $this->formatter->jsonLd($resource);
        $notes = [];
        if ($url = $this->formatter->publicUrl($resource)) {
            $notes[] = sprintf('Public page: %s', $url);
        }
        if ($cut) {
            $notes[] = $this->getJsonLdCutNote($resource, $cut);
        }
        if ($textsCut) {
            $notes[] = sprintf(
                'Text was cut after its first %s characters, where it ends "[truncated, N characters]". To read the rest of a property\'s value, call again with its "field", its "position" (from 1), and "offset" %d, without "detailed". Each call returns up to %s characters, and says where the next one starts. Text cut elsewhere, such as in an annotation, can\'t be read further through these tools.',
                number_format(ResourceFormatter::TEXT_LIMIT), ResourceFormatter::TEXT_LIMIT, number_format(ResourceFormatter::CHUNK_LIMIT)
            );
        }
        return ToolResult::json($jsonLd, $notes ? implode("\n", $notes) : null);
    }

    /**
     * Say which lists the JSON-LD form cut, and how to reach the rest of each.
     *
     * @param array $cut The full length of each list cut, by name
     */
    protected function getJsonLdCutNote(AbstractResourceEntityRepresentation $resource, array $cut): string
    {
        $names = array_keys($cut);
        $notes = [sprintf(
            'Lists longer than %d entries are cut to that many. Their full lengths: %s.',
            ResourceFormatter::LIST_LIMIT,
            implode(', ', array_map(fn ($name, $total) => sprintf('%s %d', $name, $total), $names, $cut))
        )];
        $terms = array_intersect($names, array_keys($resource->values()));
        if ($terms) {
            $notes[] = 'Call again with "field", and without "detailed", to read all of a property\'s values.';
        }
        if (isset($cut['o:media'])) {
            $notes[] = sprintf('search_media with "item" %d lists all the media.', $resource->id());
        }
        if (isset($cut['o:item_set'])) {
            $notes[] = 'The concise format lists all the item sets.';
        }
        $reverse = preg_grep('/^@reverse /', $names);
        if ($reverse) {
            // Items, media, and item sets can all link here.
            $notes[] = sprintf(
                'To find everything linking here, use a "links_to" condition on that field with the value %d: search_items finds items, search_media media, and search_item_sets item sets.',
                $resource->id()
            );
        }
        $rest = array_diff($names, $terms, $reverse, ['o:media', 'o:item_set']);
        if ($rest) {
            $notes[] = sprintf('The rest of %s can\'t be read through these tools.', implode(', ', $rest));
        }
        return implode("\n", $notes);
    }
}
