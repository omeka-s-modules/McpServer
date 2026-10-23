<?php
namespace McpServer\Mcp\Protocol;

use McpServer\Mcp\Exception\ProtocolException;
use McpServer\Mcp\Tool\Context;
use stdClass;

/**
 * Answers requests in the handshake era, 2025-11-25 and earlier.
 *
 * A client opens with initialize, then sends the agreed version in the
 * MCP-Protocol-Version header. The server keeps no session, so each request
 * after initialize is answered on its own.
 */
class HandshakeEra
{
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
     * @return object|array The JSON-RPC result
     * @throws ProtocolException
     */
    public function answer(string $method, array $params, array $headers): object|array
    {
        if ('initialize' === $method) {
            return $this->initialize($params);
        }
        $version = $headers['mcp-protocol-version'] ?? ProtocolVersion::HANDSHAKE_DEFAULT;
        if (!ProtocolVersion::isHandshake($version)) {
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
                return ['tools' => $this->toolRequests->listTools()];
            case 'tools/call':
                return $this->toolRequests->callTool($params, new Context($version));
            default:
                throw new ProtocolException(sprintf('Method not found: %s', $method), ProtocolException::METHOD_NOT_FOUND);
        }
    }

    /**
     * Answer the initialize handshake.
     *
     * @throws ProtocolException
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
            'protocolVersion' => ProtocolVersion::isHandshake($requested) ? $requested : ProtocolVersion::latestHandshake(),
            'capabilities' => $this->toolRequests->getCapabilities(),
            'serverInfo' => $this->serverInfo,
            'instructions' => $this->instructions,
        ];
    }
}
