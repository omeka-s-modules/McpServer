<?php
namespace McpServerTest\Database;

use Laminas\Http\Response;
use McpServer\Entity\McpServerKey;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use McpServer\Module;
use McpServerTest\DbTestCase;
use Omeka\Entity\ApiKey;
use Omeka\Entity\User;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Checks the /mcp endpoint over HTTP: the methods and media type it takes,
 * and MCP keys in its address.
 *
 * Each request runs in an application of its own, with its own database
 * connection, so what the requests read is committed before the tests and
 * deleted after them.
 */
class EndpointTest extends AbstractHttpControllerTestCase
{
    // Keys by name: reviewer, inactive, and deleted, an MCP key that was
    // deleted; and omeka, an Omeka API key's identity and credential.
    protected static $keys = [];

    protected static $userIds = [];

    protected static $privateItemId;

    public static function setUpBeforeClass(): void
    {
        DbTestCase::installOnce();
        $services = DbTestCase::getApplication()->getServiceManager();
        $em = $services->get('Omeka\EntityManager');
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        DbTestCase::switchUser($services, $admin);
        foreach (['reviewer' => true, 'inactive' => false] as $name => $isActive) {
            $user = DbTestCase::makeUser("mcp-endpoint-$name@example.com", "MCP endpoint $name", 'reviewer', $isActive);
            self::$userIds[$name] = $user->getId();
            self::$keys[$name] = DbTestCase::makeKey($user, 'MCP endpoint');
        }
        $reviewer = $em->find(User::class, self::$userIds['reviewer']);
        self::$keys['deleted'] = DbTestCase::makeKey($reviewer, 'MCP endpoint');
        $em->remove($em->getRepository(McpServerKey::class)->findOneBy(['hash' => McpServerKey::hashKey(self::$keys['deleted'])]));
        $apiKey = new ApiKey;
        $apiKey->setId();
        $apiKey->setLabel('MCP endpoint');
        $apiKey->setOwner($reviewer);
        $credential = $apiKey->setCredential();
        $em->persist($apiKey);
        $em->flush();
        self::$keys['omeka'] = ['key_identity' => $apiKey->getId(), 'key_credential' => $credential];
        self::$privateItemId = $services->get('Omeka\ApiManager')->create('items', ['o:is_public' => false])->getContent()->id();
    }

    public static function tearDownAfterClass(): void
    {
        $services = DbTestCase::getApplication()->getServiceManager();
        $em = $services->get('Omeka\EntityManager');
        DbTestCase::switchUser($services, $em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']));
        $services->get('Omeka\ApiManager')->delete('items', self::$privateItemId);
        foreach (self::$userIds as $id) {
            // Their keys, MCP and Omeka, go with them.
            $services->get('Omeka\ApiManager')->delete('users', $id);
        }
        DbTestCase::deleteSetting(Module::REQUIRE_SIGN_IN);
    }

    /**
     * Send a request to /mcp, in an application of its own.
     *
     * @param array|string|null $body A message, a raw body, or none
     * @param array $query The address's parameters, such as ['key' => ...]
     */
    protected function send(string $method, $body, array $headers = [], array $query = []): Response
    {
        $this->reset();
        DbTestCase::setRequestAddress('/mcp');
        $request = $this->getRequest();
        if (null !== $body) {
            $request->setContent(is_string($body) ? $body : json_encode($body));
        }
        $request->getHeaders()->addHeaders($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream']);
        $this->dispatch('/mcp' . ($query ? '?' . http_build_query($query) : ''), $method);
        return $this->getResponse();
    }

    /**
     * Send a request in the handshake era.
     */
    protected function call(string $method, array $params = [], array $query = []): Response
    {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params];
        return $this->send('POST', $body, ['MCP-Protocol-Version' => '2025-11-25'], $query);
    }

    protected function body(Response $response): array
    {
        return json_decode($response->getContent(), true) ?? [];
    }

    public function testOnlyPostIsAllowed()
    {
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $response = $this->send($method, null);
            $this->assertSame(405, $response->getStatusCode(), $method);
        }
        $this->assertSame('POST', $response->getHeaders()->get('Allow')->getFieldValue());
    }

    public function testJsonIsRequired()
    {
        $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) []]];
        $this->assertSame(415, $this->send('POST', $initialize, ['Content-Type' => 'text/plain'])->getStatusCode());
        $this->assertSame(200, $this->send('POST', $initialize, ['Content-Type' => 'application/json; charset=utf-8'])->getStatusCode());
    }

    public function testKeySignsInWithoutASession()
    {
        $read = ['name' => 'get_item', 'arguments' => ['id' => self::$privateItemId]];
        $this->assertTrue($this->body($this->call('tools/call', $read))['result']['isError']);
        $response = $this->call('tools/call', $read, ['key' => self::$keys['reviewer']]);
        $this->assertSame(200, $response->getStatusCode());
        $result = $this->body($response)['result'] ?? null;
        $this->assertNotNull($result, $response->getContent());
        $this->assertFalse($result['isError'] ?? false);
        $this->assertStringContainsString('"id":' . self::$privateItemId, $result['content'][0]['text']);
        // The route signs in only for the request, so no session, and no
        // session cookie, starts. PHP would send one outside the response
        // a test sees, so the cause is checked instead.
        $this->assertTrue($this->getApplication()->getServiceManager()->get('Omeka\Status')->isKeyauthRequest());
        $em = DbTestCase::getApplication()->getServiceManager()->get('Omeka\EntityManager');
        $em->clear();
        $key = $em->getRepository(McpServerKey::class)->findOneBy(['hash' => McpServerKey::hashKey(self::$keys['reviewer'])]);
        $this->assertNotNull($key->getLastUsed());
    }

    public function testAnswersAreNeverCached()
    {
        // An answer to a request with a key may hold private content, so no
        // answer is kept by a browser or a proxy: results and refusals alike.
        foreach ([
            'a result' => $this->call('tools/call', ['name' => 'get_overview', 'arguments' => (object) []], ['key' => self::$keys['reviewer']]),
            'a refused key' => $this->call('tools/list', [], ['key' => 'not-a-key']),
            'a wrong media type' => $this->send('POST', '{}', ['Content-Type' => 'text/plain']),
        ] as $name => $response) {
            $header = $response->getHeaders()->get('Cache-Control');
            $this->assertSame('no-store', $header ? $header->getFieldValue() : null, $name);
        }
    }

    public function testOutputPrintedDuringARequestStaysOutOfTheAnswer()
    {
        // As a PHP notice from another module would be printed. A test's
        // response isn't sent, so printed text would reach the test's own
        // output rather than the body: the endpoint must print nothing.
        $this->expectOutputString('');
        $this->reset();
        DbTestCase::setRequestAddress('/mcp');
        $this->getApplication()->getServiceManager()->get('McpServer\ToolManager')->setFactory('mcp_test_noisy', fn () => new NoisyTool);
        $request = $this->getRequest();
        $request->setContent(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'mcp_test_noisy', 'arguments' => new \stdClass]]));
        $request->getHeaders()->addHeaders(['Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2025-11-25']);
        $this->dispatch('/mcp', 'POST');
        $body = $this->body($this->getResponse());
        $this->assertSame(['said' => 'quiet'], json_decode($body['result']['content'][0]['text'] ?? 'null', true), $this->getResponse()->getContent());
    }

    public function testFailedKeysAreRefused()
    {
        foreach (['an unknown key' => ['key' => 'x'], 'an empty key' => ['key' => ''], 'a key sent as a list' => ['key' => ['x']],
            'a deleted key' => ['key' => self::$keys['deleted']], 'an inactive user\'s key' => ['key' => self::$keys['inactive']]] as $name => $query) {
            $response = $this->call('tools/list', [], $query);
            $this->assertSame([403, -32600], [$response->getStatusCode(), $this->body($response)['error']['code'] ?? null], $name);
        }
        $this->assertStringContainsString('MCP Server > Keys', $this->body($this->call('tools/list', [], ['key' => 'x']))['error']['message']);
        $response = $this->call('tools/list', [], self::$keys['omeka']);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('MCP key', $this->body($response)['error']['message']);
    }

    public function testRequireSignIn()
    {
        $settings = DbTestCase::getApplication()->getServiceManager()->get('Omeka\Settings');
        $settings->set(Module::REQUIRE_SIGN_IN, true);
        try {
            $response = $this->call('tools/list');
            $this->assertSame(403, $response->getStatusCode());
            $this->assertStringContainsString('requires an MCP key', $this->body($response)['error']['message']);
            $this->assertSame(200, $this->call('tools/list', [], ['key' => self::$keys['reviewer']])->getStatusCode());
        } finally {
            // Through the service that set it, so its cache forgets it too.
            $settings->delete(Module::REQUIRE_SIGN_IN);
        }
    }
}

/**
 * A tool that prints text while it runs, as code that emits a PHP notice
 * does.
 */
class NoisyTool implements ToolInterface
{
    public function getTitle(): string
    {
        return 'Noisy';
    }

    public function getDescription(): string
    {
        return 'Prints a notice, then answers.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        echo 'Deprecated: a module used something PHP no longer likes.';
        return ToolResult::json(['said' => 'quiet']);
    }
}
