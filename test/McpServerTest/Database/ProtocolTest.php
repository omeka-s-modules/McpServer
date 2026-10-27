<?php
namespace McpServerTest\Database;

use McpServerTest\DbTestCase;

/**
 * Checks the protocol layer through the dispatcher, which answers a request
 * body and its MCP headers as the endpoint passes them: in the handshake
 * era (2025-06-18 and 2025-11-25) and the envelope era (2026-07-28).
 */
class ProtocolTest extends DbTestCase
{
    const HANDSHAKE = '2025-11-25';

    const ENVELOPE = '2026-07-28';

    const META_VERSION = 'io.modelcontextprotocol/protocolVersion';

    const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

    /**
     * Answer a request as a visitor.
     *
     * @param array|string $body A message, or a raw body
     * @param array $headers MCP headers, by lowercase name
     * @return array [HTTP status, response body or null]
     */
    protected function handle($body, array $headers = []): array
    {
        $this->actAs(null);
        $raw = is_string($body) ? $body : json_encode($body);
        return $this->services()->get('McpServer\Dispatcher')->handle($raw, $headers);
    }

    protected function handshake(string $method, ?array $params = null, ?string $version = self::HANDSHAKE): array
    {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method] + (null === $params ? [] : ['params' => (object) $params]);
        return $this->handle($body, null === $version ? [] : ['mcp-protocol-version' => $version]);
    }

    /**
     * Send an envelope-era request. A header given as null is left out.
     */
    protected function envelope(string $method, array $params = [], array $headers = [], ?array $meta = null): array
    {
        $params['_meta'] = (object) ($meta ?? [self::META_VERSION => self::ENVELOPE, self::META_CAPABILITIES => (object) []]);
        $all = ['mcp-protocol-version' => self::ENVELOPE, 'mcp-method' => $method];
        if ('tools/call' === $method) {
            $all['mcp-name'] = $params['name'];
        }
        $all = array_filter(array_merge($all, $headers), fn ($value) => null !== $value);
        return $this->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], $all);
    }

    protected function assertError(int $status, int $code, array $response): void
    {
        $this->assertSame([$status, $code], [$response[0], $response[1]['error']['code'] ?? null], json_encode($response[1]));
    }

    protected function toolNames(): array
    {
        return $this->services()->get('McpServer\ToolManager')->getToolNames();
    }

    public function testInitializeNegotiatesTheVersion()
    {
        foreach (['2025-11-25' => '2025-11-25', '2025-06-18' => '2025-06-18', '2025-03-26' => '2025-11-25', '2026-07-28' => '2025-11-25', '1999-01-01' => '2025-11-25'] as $requested => $expected) {
            [$status, $body] = $this->handshake('initialize', ['protocolVersion' => $requested, 'capabilities' => (object) []], null);
            $this->assertSame([200, $expected], [$status, $body['result']['protocolVersion'] ?? null], $requested);
        }
        $result = $body['result'];
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertSame('omeka-mcp-server', $result['serverInfo']['name']);
        $this->assertNotEmpty($result['instructions']);
    }

    public function testHandshakeEra()
    {
        [$status, $body] = $this->handshake('ping');
        $this->assertSame(200, $status);
        $this->assertEmpty((array) $body['result']);
        [, $body] = $this->handshake('tools/list');
        $this->assertSame($this->toolNames(), array_column($body['result']['tools'], 'name'));
        $this->assertArrayNotHasKey('resultType', $body['result']);
        $this->assertSame(400, $this->handshake('tools/list', null, null)[0]);
        $this->assertSame(400, $this->handshake('tools/list', null, '2024-11-05')[0]);
        $this->assertError(200, -32602, $this->handshake('tools/call', ['name' => 'no_such_tool', 'arguments' => (object) []]));
        $this->assertError(200, -32601, $this->handshake('resources/list', []));
        [, $body] = $this->handshake('tools/call', ['name' => 'get_item', 'arguments' => (object) []]);
        $this->assertTrue($body['result']['isError']);
    }

    public function testMalformedRequests()
    {
        $this->assertError(400, -32600, $this->handle([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']]));
        $this->assertError(400, -32700, $this->handle('{"jsonrpc": '));
        $this->assertError(400, -32600, $this->handle('"just a string"'));
        $this->assertError(400, -32600, $this->handle(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'], ['mcp-protocol-version' => self::HANDSHAKE]));
        $this->assertSame([202, null], $this->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['mcp-protocol-version' => self::HANDSHAKE]));
        $this->assertSame([202, null], $this->handle(['jsonrpc' => '2.0', 'id' => 9, 'result' => (object) []]));
    }

    public function testEnvelopeEra()
    {
        [$status, $body] = $this->envelope('server/discover');
        $result = $body['result'];
        $this->assertSame([200, 'complete', 'public'], [$status, $result['resultType'], $result['cacheScope']]);
        $this->assertContains(self::ENVELOPE, $result['supportedVersions']);
        $this->assertContains(self::HANDSHAKE, $result['supportedVersions']);
        $this->assertSame('omeka-mcp-server', $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        [, $body] = $this->envelope('tools/list');
        $this->assertSame(['complete', 'public'], [$body['result']['resultType'], $body['result']['cacheScope']]);
        $this->assertSame($this->toolNames(), array_column($body['result']['tools'], 'name'));
        [, $body] = $this->envelope('tools/call', ['name' => 'get_overview', 'arguments' => (object) []]);
        $this->assertSame('complete', $body['result']['resultType']);
        $this->assertArrayNotHasKey('ttlMs', $body['result']);
        $encoded = '=?base64?' . base64_encode('get_overview') . '?=';
        $this->assertSame(200, $this->envelope('tools/call', ['name' => 'get_overview', 'arguments' => (object) []], ['mcp-name' => $encoded])[0]);
    }

    public function testEnvelopeErrors()
    {
        $this->assertError(400, -32602, $this->envelope('tools/list', [], [], [self::META_VERSION => self::ENVELOPE]));
        $this->assertError(400, -32602, $this->envelope('tools/list', [], [], [self::META_CAPABILITIES => (object) []]));
        $this->assertError(400, -32020, $this->envelope('tools/list', [], ['mcp-method' => null]));
        $this->assertError(400, -32020, $this->envelope('tools/list', [], ['mcp-method' => 'tools/call']));
        $call = ['name' => 'get_overview', 'arguments' => (object) []];
        $this->assertError(400, -32020, $this->envelope('tools/call', $call, ['mcp-name' => null]));
        $this->assertError(400, -32020, $this->envelope('tools/call', $call, ['mcp-name' => 'get_item']));
        $this->assertError(400, -32020, $this->envelope('tools/list', [], ['mcp-protocol-version' => self::HANDSHAKE]));
        $response = $this->envelope('tools/list', [], ['mcp-protocol-version' => '1900-01-01'], [self::META_VERSION => '1900-01-01', self::META_CAPABILITIES => (object) []]);
        $this->assertError(400, -32022, $response);
        $this->assertContains(self::ENVELOPE, $response[1]['error']['data']['supported']);
        $this->assertError(404, -32601, $this->envelope('ping'));
        $this->assertError(404, -32601, $this->envelope('initialize', ['protocolVersion' => self::HANDSHAKE, 'capabilities' => (object) []]));
        $this->assertError(400, -32602, $this->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['mcp-protocol-version' => self::ENVELOPE, 'mcp-method' => 'tools/list']));
    }

    public function testToolListIsTheSameForEveryCaller()
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $headers = ['mcp-protocol-version' => self::HANDSHAKE];
        $anonymous = $this->handle($body, $headers);
        $this->actAs($this->adminUser());
        $admin = $this->services()->get('McpServer\Dispatcher')->handle($body, $headers);
        $this->assertSame($anonymous, $admin);
    }
}
