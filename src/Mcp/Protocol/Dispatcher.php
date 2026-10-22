<?php
namespace McpServer\Mcp\Protocol;

use Laminas\Log\LoggerInterface;
use McpServer\Mcp\Exception\ProtocolException;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\Manager as ToolManager;
use McpServer\Mcp\Tool\ToolResult;
use McpServer\Mcp\Tool\ToolRunner;
use stdClass;
use Throwable;

/**
 * Answers MCP JSON-RPC messages.
 *
 * Everything JSON-RPC happens here: decoding a request body, checking it,
 * routing it, and building the result or the error, with the HTTP status the
 * spec gives it. The controller handles only HTTP and Omeka policy.
 *
 * Serves both protocol eras. A request in the 2026-07-28 era carries its
 * version in _meta and needs no handshake. Earlier clients open with
 * initialize, then send their negotiated version in a header. Nothing is kept
 * between requests in either era.
 */
class Dispatcher
{
    // How responses are encoded, the same as tool results inside them.
    const JSON_FLAGS = ToolResult::JSON_FLAGS;

    const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
    const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    // How long clients may cache the tool list and discovery results, so that
    // tools added by an upgrade appear without reconnecting.
    const TTL_MS = 300000;

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

    protected $serverInfo;

    protected $instructions;

    public function __construct(ToolManager $toolManager, ToolRunner $toolRunner, LoggerInterface $logger, array $serverInfo, string $instructions)
    {
        $this->toolManager = $toolManager;
        $this->toolRunner = $toolRunner;
        $this->logger = $logger;
        $this->serverInfo = $serverInfo;
        $this->instructions = $instructions;
    }

    /**
     * Answer one request body.
     *
     * Every failure becomes a JSON-RPC error response, so nothing is thrown.
     *
     * @param string $body The raw request body
     * @param array $headers The MCP request headers, keyed by lowercase name
     * @param callable|null $check Called once the message is decoded, before
     *   it's answered, for a caller's own policy. A ProtocolException it
     *   throws is answered like any other, with the message's id.
     * @return array [HTTP status, response body, or null when there is
     *   nothing to send back]
     */
    public function handle(string $body, array $headers, ?callable $check = null): array
    {
        $id = null;
        try {
            $message = $this->decode($body);
            $id = self::getRequestId($message);
            if ($check) {
                $check();
            }
            $response = $this->dispatch($message, $headers);
            return null === $response ? [202, null] : [200, $response];
        } catch (ProtocolException $e) {
            return [$e->getHttpStatus(), self::errorResponse($id, $e->getCode(), $e->getMessage(), $e->getData())];
        } catch (Throwable $e) {
            $this->logger->err(sprintf('MCP Server: %s', $e));
            return [200, self::errorResponse($id, ProtocolException::INTERNAL_ERROR, 'Internal error')];
        }
    }

    /**
     * Build the internal error response for a request the server failed to
     * answer, with the message's id when the body has one.
     *
     * For the controller, when the dispatcher itself can't be built.
     */
    public static function failureResponse(string $body): array
    {
        $message = json_decode($body, true);
        $id = is_array($message) ? self::getRequestId($message) : null;
        return self::errorResponse($id, ProtocolException::INTERNAL_ERROR, 'Internal error');
    }

    /**
     * Build a JSON-RPC error response.
     *
     * Public for the controller's errors that come before a message is read,
     * such as the wrong media type, which have no id.
     *
     * @param string|int|null $id The id of the message being answered, or
     *   null when it's unknown
     */
    public static function errorResponse($id, int $code, string $message, $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if (null !== $data) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    /**
     * Decode a request body into one JSON-RPC message.
     *
     * @throws ProtocolException
     */
    protected function decode(string $body): array
    {
        $message = json_decode($body, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new ProtocolException('Parse error', ProtocolException::PARSE_ERROR, 400);
        }
        if (!is_array($message)) {
            throw new ProtocolException('Invalid request: the body must be a JSON object.', ProtocolException::INVALID_REQUEST, 400);
        }
        if ($message && array_is_list($message)) {
            throw new ProtocolException('Invalid request: batches are not supported. Send one message per request.', ProtocolException::INVALID_REQUEST, 400);
        }
        return $message;
    }

    /**
     * Get a message's id, or null when it has no valid one.
     */
    protected static function getRequestId(array $message): string|int|null
    {
        $id = $message['id'] ?? null;
        return (is_string($id) || is_int($id)) ? $id : null;
    }

    /**
     * Answer one decoded JSON-RPC message.
     *
     * @param array $message The decoded message
     * @param array $headers The MCP request headers, keyed by lowercase name
     * @return array|null The response, or null when there is nothing to send
     *     back: the message was a notification or a client's response
     * @throws ProtocolException
     */
    protected function dispatch(array $message, array $headers): ?array
    {
        if ('2.0' !== ($message['jsonrpc'] ?? null)) {
            throw new ProtocolException('Invalid request: jsonrpc must be "2.0".', ProtocolException::INVALID_REQUEST, 400);
        }
        if (!array_key_exists('method', $message)) {
            // A response to a request from the server. This server sends none,
            // so there is nothing to do with it.
            if (array_key_exists('id', $message) && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
                return null;
            }
            throw new ProtocolException('Invalid request: method is missing.', ProtocolException::INVALID_REQUEST, 400);
        }
        $method = $message['method'];
        $params = $message['params'] ?? [];
        if (!is_string($method) || !is_array($params)) {
            throw new ProtocolException('Invalid request: method must be a string and params an object.', ProtocolException::INVALID_REQUEST, 400);
        }
        if (!array_key_exists('id', $message)) {
            // A notification. None needs an answer, and this server acts on none.
            return null;
        }
        $id = self::getRequestId($message);
        if (null === $id) {
            throw new ProtocolException('Invalid request: id must be a string or an integer.', ProtocolException::INVALID_REQUEST, 400);
        }

        // Modern markers come first: in the 2026-07-28 era, initialize is a
        // removed method rather than the start of a legacy handshake.
        if ($this->isModern($params, $headers)) {
            $result = $this->dispatchModern($method, $params, $headers);
        } elseif ('initialize' === $method) {
            $result = $this->initialize($params);
        } else {
            $result = $this->dispatchLegacy($method, $params, $headers);
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * Whether a request is in the 2026-07-28 era.
     *
     * Either _meta carries a protocol version, or the header names a modern
     * one. The header matters for requests that leave out _meta, so they can
     * be told what's missing rather than taken for legacy requests.
     */
    protected function isModern(array $params, array $headers): bool
    {
        return isset($params['_meta'][self::META_PROTOCOL_VERSION])
            || ProtocolVersion::isModern($headers['mcp-protocol-version'] ?? '');
    }

    /**
     * Answer a request in the 2026-07-28 era.
     */
    protected function dispatchModern(string $method, array $params, array $headers): array
    {
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $version = $meta[self::META_PROTOCOL_VERSION] ?? null;
        if (!is_string($version) || !isset($meta[self::META_CLIENT_CAPABILITIES]) || !is_array($meta[self::META_CLIENT_CAPABILITIES])) {
            throw new ProtocolException(
                sprintf('Invalid params: _meta must include %s and %s.', self::META_PROTOCOL_VERSION, self::META_CLIENT_CAPABILITIES),
                ProtocolException::INVALID_PARAMS, 400
            );
        }
        // A header that contradicts the body is a mismatch, whatever versions
        // they name. Only then is the version itself checked.
        $this->checkHeader($headers, 'mcp-protocol-version', 'MCP-Protocol-Version', $version);
        if (!ProtocolVersion::isModern($version)) {
            throw new ProtocolException(
                'Unsupported protocol version',
                ProtocolException::UNSUPPORTED_PROTOCOL_VERSION, 400,
                ['supported' => ProtocolVersion::supported(), 'requested' => $version]
            );
        }
        $this->checkHeader($headers, 'mcp-method', 'Mcp-Method', $method);
        if ('tools/call' === $method && is_string($params['name'] ?? null)) {
            $this->checkHeader($headers, 'mcp-name', 'Mcp-Name', $params['name'], true);
        }

        switch ($method) {
            case 'server/discover':
                $result = [
                    'supportedVersions' => ProtocolVersion::supported(),
                    'capabilities' => $this->getCapabilities(),
                    'instructions' => $this->instructions,
                    'ttlMs' => self::TTL_MS,
                    'cacheScope' => 'public',
                ];
                break;
            case 'tools/list':
                $result = [
                    'tools' => $this->listTools(),
                    'ttlMs' => self::TTL_MS,
                    'cacheScope' => 'public',
                ];
                break;
            case 'tools/call':
                $result = $this->callTool($params, new Context($version));
                break;
            default:
                throw new ProtocolException(sprintf('Method not found: %s', $method), ProtocolException::METHOD_NOT_FOUND, 404);
        }
        return ['resultType' => 'complete'] + $result + ['_meta' => [self::META_SERVER_INFO => $this->serverInfo]];
    }

    /**
     * Answer a request in the initialize era, after the handshake.
     */
    protected function dispatchLegacy(string $method, array $params, array $headers): object|array
    {
        $version = $headers['mcp-protocol-version'] ?? ProtocolVersion::LEGACY_DEFAULT;
        if (!ProtocolVersion::isLegacy($version)) {
            throw new ProtocolException(
                sprintf('Unsupported protocol version "%s". Send a supported MCP-Protocol-Version header.', $version),
                ProtocolException::INVALID_REQUEST, 400,
                ['supported' => ProtocolVersion::supported(), 'requested' => $version]
            );
        }
        switch ($method) {
            case 'ping':
                return new stdClass;
            case 'tools/list':
                return ['tools' => $this->listTools()];
            case 'tools/call':
                return $this->callTool($params, new Context($version));
            default:
                throw new ProtocolException(sprintf('Method not found: %s', $method), ProtocolException::METHOD_NOT_FOUND);
        }
    }

    /**
     * Answer the initialize handshake.
     */
    protected function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        if (!is_string($requested)) {
            throw new ProtocolException('Invalid params: initialize requires protocolVersion.', ProtocolException::INVALID_PARAMS);
        }
        // Echo a supported version, otherwise offer the newest this era has.
        // A client asking for 2026-07-28 gets that too, since that version has
        // no initialize.
        return [
            'protocolVersion' => ProtocolVersion::isLegacy($requested) ? $requested : ProtocolVersion::latestLegacy(),
            'capabilities' => $this->getCapabilities(),
            'serverInfo' => $this->serverInfo,
            'instructions' => $this->instructions,
        ];
    }

    protected function getCapabilities(): array
    {
        return ['tools' => ['listChanged' => false]];
    }

    /**
     * Describe the registered tools.
     */
    protected function listTools(): array
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
     */
    protected function callTool(array $params, Context $context): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || !$this->toolRunner->has($name)) {
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
     * Check that a request header matches the request body.
     */
    protected function checkHeader(array $headers, string $name, string $label, string $expected, bool $mayBeEncoded = false): void
    {
        $value = $headers[$name] ?? null;
        if (null === $value) {
            throw new ProtocolException(sprintf('Header mismatch: the %s header is missing.', $label), ProtocolException::HEADER_MISMATCH, 400);
        }
        // Values that aren't safe as plain header text arrive base64 encoded.
        if ($mayBeEncoded && preg_match('/^=\?base64\?([A-Za-z0-9+\/]*={0,2})\?=$/', $value, $matches)) {
            $value = base64_decode($matches[1], true);
        }
        if ($value !== $expected) {
            throw new ProtocolException(
                sprintf('Header mismatch: %s header value does not match body value \'%s\'.', $label, mb_substr($expected, 0, 100)),
                ProtocolException::HEADER_MISMATCH, 400
            );
        }
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
