<?php
namespace McpServer\Mcp\Protocol;

use McpServer\Mcp\Exception\ProtocolException;
use McpServer\Mcp\Tool\Context;

/**
 * Answers requests in the envelope era, 2026-07-28 and later.
 *
 * There is no handshake. Each request carries its protocol version and the
 * client's capabilities in its _meta envelope, and headers repeat the version,
 * the method, and the tool name, which must match the body.
 */
class EnvelopeEra
{
    const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
    const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    // How long clients may cache the tool list and discovery results, so that
    // tools added by an upgrade appear without reconnecting.
    const TTL_MS = 300000;

    protected $toolRequests;

    protected $serverInfo;

    protected $instructions;

    public function __construct(ToolRequests $toolRequests, array $serverInfo, string $instructions)
    {
        $this->toolRequests = $toolRequests;
        $this->serverInfo = $serverInfo;
        $this->instructions = $instructions;
    }

    /**
     * Answer one request.
     *
     * @return array The JSON-RPC result
     * @throws ProtocolException
     */
    public function answer(string $method, array $params, array $headers): array
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
        if (!ProtocolVersion::isEnvelope($version)) {
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
                    'capabilities' => $this->toolRequests->getCapabilities(),
                    'instructions' => $this->instructions,
                    'ttlMs' => self::TTL_MS,
                    'cacheScope' => 'public',
                ];
                break;
            case 'tools/list':
                $result = [
                    'tools' => $this->toolRequests->listTools(),
                    'ttlMs' => self::TTL_MS,
                    'cacheScope' => 'public',
                ];
                break;
            case 'tools/call':
                $result = $this->toolRequests->callTool($params, new Context($version));
                break;
            default:
                throw new ProtocolException(sprintf('Method not found: %s', $method), ProtocolException::METHOD_NOT_FOUND, 404);
        }
        return ['resultType' => 'complete'] + $result + ['_meta' => [self::META_SERVER_INFO => $this->serverInfo]];
    }

    /**
     * Check that a request header matches the request body.
     *
     * @throws ProtocolException
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
}
