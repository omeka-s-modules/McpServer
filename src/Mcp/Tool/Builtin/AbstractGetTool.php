<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Mcp\Format\JsonLdFormatter;
use McpServer\Mcp\Format\ResourceFormatter;
use McpServer\Mcp\Format\ValueFormatter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * The base of get_item and get_item_set, which read one resource the same way:
 * in a concise form by default, or as Omeka's own JSON-LD on request. Both can
 * also read one field in full, through ReadsFieldsTrait.
 */
abstract class AbstractGetTool implements ToolInterface
{
    use ReadsFieldsTrait;

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
     * Get the API resource read, "items" or "item_sets".
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
            throw new ToolException(sprintf('No %s with id %d exists or is visible to you.', $this->getSingularNoun(), $id));
        }
        if ($reader->has('field')) {
            if ('detailed' === $format) {
                throw new ToolException('"field" reads readable values, so it doesn\'t take "response_format" "detailed".');
            }
            return $this->readField($resource, $reader, $this->getSingularNoun());
        }
        if ('detailed' !== $format) {
            $concise = $this->getConcise($resource);
            return ToolResult::json($concise, OutputLimitNotes::forConcise($concise));
        }
        [$jsonLd, $cut, $textsCut] = $this->jsonLdFormatter->jsonLd($resource);
        $url = $this->formatter->publicUrl($resource);
        $notes = array_filter([$url ? sprintf('Public page: %s', $url) : null, OutputLimitNotes::forJsonLd($resource, $cut, $textsCut)]);
        return ToolResult::json($jsonLd, $notes ? implode("\n", $notes) : null);
    }

    protected function getFormatter(): ResourceFormatter
    {
        return $this->formatter;
    }

    protected function getValueFormatter(): ValueFormatter
    {
        return $this->valueFormatter;
    }
}
