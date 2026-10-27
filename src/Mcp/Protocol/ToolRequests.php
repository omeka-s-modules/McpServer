<?php
namespace McpServer\Mcp\Protocol;

use Laminas\Log\LoggerInterface;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\Manager as ToolManager;
use McpServer\Mcp\Tool\ToolRunner;
use stdClass;
use Throwable;

/**
 * Answers what every era asks of the server: its capabilities, its tools, and
 * running one.
 *
 * Nothing here depends on the era. A feature that starts in a particular
 * protocol version goes here too, behind a check of the version.
 */
class ToolRequests
{
    // JSON Schema keywords whose value is a map of names to subschemas.
    const SCHEMA_MAP_KEYWORDS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    // JSON Schema keywords whose value is one subschema, or a list of them.
    const SCHEMA_KEYWORDS = [
        'items', 'prefixItems', 'additionalItems', 'additionalProperties', 'unevaluatedItems',
        'unevaluatedProperties', 'propertyNames', 'contains', 'not', 'if', 'then', 'else',
        'allOf', 'anyOf', 'oneOf',
    ];

    protected $toolManager;

    protected $toolRunner;

    protected $logger;

    public function __construct(ToolManager $toolManager, ToolRunner $toolRunner, LoggerInterface $logger)
    {
        $this->toolManager = $toolManager;
        $this->toolRunner = $toolRunner;
        $this->logger = $logger;
    }

    public function getCapabilities(): array
    {
        return ['tools' => ['listChanged' => false]];
    }

    /**
     * Describe the registered tools.
     */
    public function listTools(): array
    {
        $tools = [];
        foreach ($this->toolManager->getToolNames() as $name) {
            try {
                $tool = $this->toolManager->get($name);
                $definition = [
                    'name' => $name,
                    'title' => $tool->getTitle(),
                    'description' => $tool->getDescription(),
                    'inputSchema' => $this->encodeSchema($tool->getInputSchema()),
                ];
                $annotations = $tool->getAnnotations();
                if ($annotations) {
                    $definition['annotations'] = $annotations;
                }
            } catch (Throwable $e) {
                // One broken tool shouldn't hide the others.
                $this->logger->err(sprintf('MCP Server: could not describe the tool "%s": %s', $name, $e));
                continue;
            }
            $tools[] = $definition;
        }
        return $tools;
    }

    /**
     * Run a tool.
     *
     * Only an unknown tool or malformed params are protocol errors. The runner
     * turns failures inside the tool into error results.
     *
     * @throws ProtocolException
     */
    public function callTool(array $params, Context $context): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || !$this->toolRunner->canRun($name)) {
            throw new ProtocolException(
                sprintf('Unknown tool: %s', is_string($name) ? $name : '(no name given)'),
                ProtocolException::INVALID_PARAMS
            );
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new ProtocolException('Invalid params: arguments must be an object.', ProtocolException::INVALID_PARAMS);
        }
        $result = $this->toolRunner->run($name, $arguments, $context);
        $output = ['content' => $result->getContent()];
        if ($result->isError()) {
            $output['isError'] = true;
        }
        return $output;
    }

    /**
     * Prepare a JSON Schema for encoding.
     *
     * PHP encodes an empty array as [], but an empty schema, which accepts
     * anything, and an empty map must be {}.
     */
    protected function encodeSchema(array $schema): object|array
    {
        if (!$schema) {
            return new stdClass;
        }
        foreach ($schema as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (in_array($key, self::SCHEMA_MAP_KEYWORDS, true)) {
                $schema[$key] = $value ? array_map(fn ($subschema) => $this->encodeSubschema($subschema), $value) : new stdClass;
            } elseif (in_array($key, self::SCHEMA_KEYWORDS, true)) {
                $schema[$key] = ($value && array_is_list($value))
                    ? array_map(fn ($subschema) => $this->encodeSubschema($subschema), $value)
                    : $this->encodeSchema($value);
            }
        }
        return $schema;
    }

    protected function encodeSubschema($subschema)
    {
        return is_array($subschema) ? $this->encodeSchema($subschema) : $subschema;
    }
}
