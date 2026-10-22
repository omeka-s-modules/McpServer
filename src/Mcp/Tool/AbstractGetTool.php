<?php
namespace McpServer\Mcp\Tool;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Stdlib\ItemFormatter;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * What get_item and get_item_set share, so the two read a resource the same
 * way: a concise form by default, or Omeka's own JSON-LD on request.
 */
abstract class AbstractGetTool implements ToolInterface
{
    const FORMATS = ['concise', 'detailed'];

    protected $api;

    protected $formatter;

    public function __construct(ApiManager $api, ItemFormatter $formatter)
    {
        $this->api = $api;
        $this->formatter = $formatter;
    }

    /**
     * Get the API resource read, "items" or "item_sets".
     */
    abstract protected function getResourceName(): string;

    /**
     * Get what a resource is called in messages, such as "item set".
     */
    abstract protected function getLabel(): string;

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
                    'description' => sprintf('The %s\'s id.', $this->getLabel()),
                ],
                'response_format' => [
                    'type' => 'string',
                    'enum' => self::FORMATS,
                    'default' => 'concise',
                    'description' => '"concise" for readable values with labels, or "detailed" for Omeka\'s full JSON-LD.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function getAnnotations(): array
    {
        return Annotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $reader = new ArgumentReader($arguments);
        $id = $reader->requireInt('id', 1);
        $format = $reader->getEnum('response_format', self::FORMATS, 'concise');
        try {
            $resource = $this->api->read($this->getResourceName(), $id)->getContent();
        } catch (NotFoundException $e) {
            throw new ToolException(sprintf('No %s with id %d exists or is visible to you.', $this->getLabel(), $id));
        }
        if ('detailed' !== $format) {
            return ToolResult::json($this->getConcise($resource));
        }
        [$jsonLd, $cut] = $this->formatter->jsonLd($resource);
        $notes = [];
        if ($url = $this->formatter->publicUrl($resource)) {
            $notes[] = sprintf('Public page: %s', $url);
        }
        if ($cut) {
            $notes[] = sprintf(
                'Lists longer than %d entries are cut to that many. Their full lengths: %s.',
                ItemFormatter::LIST_LIMIT,
                implode(', ', array_map(fn ($name, $total) => sprintf('%s %d', $name, $total), array_keys($cut), $cut))
            );
        }
        if (preg_grep('/^@reverse /', array_keys($cut))) {
            // Items, media, and item sets can all link here.
            $notes[] = sprintf(
                'To find everything linking here, use a "links_to" condition on that field with the value %d: search_items finds items, search_media media, and search_item_sets item sets.',
                $id
            );
        }
        return ToolResult::json($jsonLd, $notes ? implode("\n", $notes) : null);
    }
}
