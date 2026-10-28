<?php
namespace McpServerTest\Database;

use McpServer\Mcp\Protocol\ProtocolException;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\Manager;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use McpServerTest\DbTestCase;
use RuntimeException;

/**
 * Checks tools another module adds, as the README's "Adding tools from other
 * modules" describes: registered in its config under mcp_server.tools, a tool
 * is listed after the built-in tools and runs like them, a broken one hides no
 * other, a name clients can't use is skipped, and a listener can switch one
 * off.
 */
class AddedToolsTest extends DbTestCase
{
    // The application's config, restored after each test.
    protected $config;

    public function setUp(): void
    {
        parent::setUp();
        $this->config = $this->services()->get('Config');
    }

    public function tearDown(): void
    {
        $this->setConfig($this->config);
        parent::tearDown();
    }

    /**
     * Register tools as another module's config does, once Omeka has merged
     * it into the application's.
     */
    protected function addTools(array $factories): void
    {
        $config = $this->config;
        $config['mcp_server']['tools']['factories'] += $factories;
        $this->setConfig($config);
    }

    protected function setConfig(array $config): void
    {
        $services = $this->services();
        $services->setAllowOverride(true);
        $services->setService('Config', $config);
        $services->setAllowOverride(false);
    }

    /**
     * Get the names tools/list gives, in order.
     */
    protected function listedNames(): array
    {
        return array_column($this->services()->get('McpServer\ToolRequests')->listTools(), 'name');
    }

    public function testAnAddedToolIsListedAfterTheBuiltInToolsAndRuns()
    {
        $builtIn = $this->listedNames();
        $this->addTools(['mymodule_echo' => fn () => new EchoTool]);
        $this->assertSame(array_merge($builtIn, ['mymodule_echo']), $this->listedNames());
        $this->assertSame(['said' => 'hello'], $this->resultData($this->callTool('mymodule_echo', ['text' => 'hello'])));
        // An argument its schema doesn't name is refused, as for the built-in
        // tools.
        $this->assertStringContainsString('Unknown argument "txt"', $this->resultText($this->callTool('mymodule_echo', ['txt' => 'hello'])));
    }

    public function testABrokenToolHidesNoOther()
    {
        $this->addTools([
            'mymodule_broken' => function () {
                throw new RuntimeException('Misconfigured');
            },
            'mymodule_echo' => fn () => new EchoTool,
        ]);
        $names = $this->listedNames();
        $this->assertNotContains('mymodule_broken', $names);
        $this->assertContains('mymodule_echo', $names);
        $this->assertContains('get_overview', $names);
        $result = $this->callTool('mymodule_broken');
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('The tool failed', $this->resultText($result));
    }

    public function testEmptySchemaMapsAreSentAsObjects()
    {
        // PHP encodes an empty array as [], which JSON Schema doesn't
        // accept where it expects an object, so a client could refuse the
        // whole tool list.
        $this->addTools([
            'mymodule_nested' => fn () => new SchemaTool(['type' => 'object', 'properties' => ['options' => ['type' => 'object', 'properties' => []]]]),
            'mymodule_open' => fn () => new SchemaTool([]),
        ]);
        $schemas = array_column($this->services()->get('McpServer\ToolRequests')->listTools(), 'inputSchema', 'name');
        $this->assertSame('{"type":"object","properties":{"options":{"type":"object","properties":{}}}}', json_encode($schemas['mymodule_nested']));
        $this->assertSame('{}', json_encode($schemas['mymodule_open']));
    }

    public function testANameClientsCantUseIsSkipped()
    {
        $this->addTools(['mymodule echo' => fn () => new EchoTool]);
        $this->assertNotContains('mymodule echo', $this->listedNames());
        $this->assertFalse($this->services()->get('McpServer\ToolRunner')->canRun('mymodule echo'));
    }

    public function testAListenerCanSwitchAToolOff()
    {
        $this->addTools(['mymodule_echo' => fn () => new EchoTool]);
        $events = $this->services()->get('SharedEventManager');
        $listener = function ($event) {
            $event->setParam('registered_names', array_values(array_diff($event->getParam('registered_names'), ['mymodule_echo'])));
        };
        $events->attach(Manager::class, 'service.registered_names', $listener);
        try {
            $this->assertNotContains('mymodule_echo', $this->listedNames());
            $this->expectException(ProtocolException::class);
            $this->expectExceptionMessage('Unknown tool: mymodule_echo');
            $this->services()->get('McpServer\ToolRequests')->callTool(['name' => 'mymodule_echo', 'arguments' => []], new Context(self::PROTOCOL_VERSION));
        } finally {
            $events->detach($listener, Manager::class);
        }
    }
}

/**
 * A tool as another module might add, which says back the text it's given.
 */
class EchoTool implements ToolInterface
{
    public function getTitle(): string
    {
        return 'Echo';
    }

    public function getDescription(): string
    {
        return 'Says back the text it\'s given.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'What to say back.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        return ToolResult::json(['said' => (new ArgumentReader($arguments))->getText('text')]);
    }
}

/**
 * A tool with whatever input schema it's given.
 */
class SchemaTool extends EchoTool
{
    protected $schema;

    public function __construct(array $schema)
    {
        $this->schema = $schema;
    }

    public function getInputSchema(): array
    {
        return $this->schema;
    }
}
