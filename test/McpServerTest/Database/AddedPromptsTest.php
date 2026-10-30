<?php
namespace McpServerTest\Database;

use McpServer\Mcp\Context;
use McpServer\Mcp\Prompt\Manager;
use McpServer\Mcp\Prompt\PromptException;
use McpServer\Mcp\Prompt\PromptInterface;
use McpServer\Mcp\Prompt\PromptResult;
use McpServer\Mcp\Protocol\ProtocolException;
use McpServerTest\DbTestCase;
use Omeka\Api\Manager as ApiManager;
use RuntimeException;

/**
 * Checks prompts another module adds, as the README's "Adding tools and
 * prompts from other modules" describes: registered in its config under
 * mcp_server.prompts, a prompt is listed after the built-in prompts and works
 * like them, a broken one hides no other, a name clients can't use is
 * skipped, and a listener can switch one off.
 */
class AddedPromptsTest extends DbTestCase
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
     * Register prompts as another module's config does, once Omeka has
     * merged it into the application's.
     */
    protected function addPrompts(array $factories): void
    {
        $config = $this->config;
        $config['mcp_server']['prompts']['factories'] += $factories;
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
     * Get the names prompts/list gives, in order.
     */
    protected function listedNames(): array
    {
        return array_column($this->services()->get('McpServer\PromptRequests')->listPrompts(), 'name');
    }

    protected function getPrompt(string $name, array $arguments = []): array
    {
        return $this->services()->get('McpServer\PromptRequests')->getPrompt(['name' => $name, 'arguments' => $arguments], new Context(self::PROTOCOL_VERSION));
    }

    public function testAnAddedPromptIsListedAfterTheBuiltInPromptsAndWorks()
    {
        $builtIn = $this->listedNames();
        $this->addPrompts(['mymodule_compare' => fn () => new ComparePrompt]);
        $this->assertSame(array_merge($builtIn, ['mymodule_compare']), $this->listedNames());
        $result = $this->getPrompt('mymodule_compare', ['first' => '12', 'second' => ' 34 ']);
        $this->assertSame('Compare the shared item and the pages.', $result['description']);
        $this->assertSame([['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Compare items 12 and 34.']]], $result['messages']);
    }

    public function testAPromptGetsOnlyTheArgumentsItDeclares()
    {
        $this->addPrompts(['mymodule_compare' => fn () => new ComparePrompt]);
        foreach ([
            'a missing required argument' => ['first' => '12'],
            'a blank required argument' => ['first' => '12', 'second' => '  '],
            'an argument it doesn\'t declare' => ['first' => '12', 'second' => '34', 'third' => '56'],
            'an argument that isn\'t text' => ['first' => '12', 'second' => ['34']],
        ] as $case => $arguments) {
            try {
                $this->getPrompt('mymodule_compare', $arguments);
                $this->fail($case);
            } catch (ProtocolException $e) {
                $this->assertSame(ProtocolException::INVALID_PARAMS, $e->getCode(), $case);
            }
        }
    }

    public function testAPromptCanRefuseAnArgumentWithAMessage()
    {
        $this->addPrompts(['mymodule_compare' => fn () => new ComparePrompt]);
        try {
            $this->getPrompt('mymodule_compare', ['first' => '12', 'second' => 'abc']);
            $this->fail('The prompt accepted "abc" as an item id.');
        } catch (ProtocolException $e) {
            $this->assertSame([ProtocolException::INVALID_PARAMS, 'second must be an item id, such as 12.'], [$e->getCode(), $e->getMessage()]);
        }
    }

    public function testARecordItCantReadIsRefusedAsForTools()
    {
        // As a tool's API exception becomes an error result, a prompt's
        // becomes a refusal the user can read, not an internal error.
        $this->addPrompts(['mymodule_item' => fn ($services) => new ItemPrompt($services->get('Omeka\ApiManager'))]);
        try {
            $this->getPrompt('mymodule_item', ['id' => '999999999']);
            $this->fail('A missing item was not refused.');
        } catch (ProtocolException $e) {
            $this->assertSame(ProtocolException::INVALID_PARAMS, $e->getCode());
            $this->assertStringContainsString('doesn\'t exist or isn\'t visible to you', $e->getMessage());
        }
    }

    public function testABrokenPromptHidesNoOther()
    {
        $this->addPrompts([
            'mymodule_broken' => function () {
                throw new RuntimeException('Misconfigured');
            },
            'mymodule_compare' => fn () => new ComparePrompt,
        ]);
        $names = $this->listedNames();
        $this->assertNotContains('mymodule_broken', $names);
        $this->assertContains('mymodule_compare', $names);
        $this->assertContains('overview', $names);
        // Getting it fails like any request the server can't answer: the
        // error is logged, and the client gets an internal error.
        [, $body] = $this->services()->get('McpServer\Dispatcher')->handle(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/get', 'params' => ['name' => 'mymodule_broken']]),
            ['mcp-protocol-version' => self::PROTOCOL_VERSION]
        );
        $this->assertSame([1, ProtocolException::INTERNAL_ERROR], [$body['id'], $body['error']['code']]);
    }

    public function testANameClientsCantUseIsSkipped()
    {
        $this->addPrompts(['mymodule compare' => fn () => new ComparePrompt]);
        $this->assertNotContains('mymodule compare', $this->listedNames());
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unknown prompt: mymodule compare');
        $this->getPrompt('mymodule compare', ['first' => '1', 'second' => '2']);
    }

    public function testAListenerCanSwitchAPromptOff()
    {
        $events = $this->services()->get('SharedEventManager');
        $listener = function ($event) {
            $event->setParam('registered_names', array_values(array_diff($event->getParam('registered_names'), ['research'])));
        };
        $events->attach(Manager::class, 'service.registered_names', $listener);
        try {
            $this->assertSame(['overview'], $this->listedNames());
            $this->expectException(ProtocolException::class);
            $this->expectExceptionMessage('Unknown prompt: research');
            $this->getPrompt('research', ['topic' => 'maps']);
        } finally {
            $events->detach($listener, Manager::class);
        }
    }
}

/**
 * A prompt as another module might add, which asks to compare two items.
 */
class ComparePrompt implements PromptInterface
{
    public function getTitle(): string
    {
        return 'Compare two items';
    }

    public function getDescription(): string
    {
        return 'Compare the shared item and the pages.';
    }

    public function getArguments(): array
    {
        return [
            ['name' => 'first', 'description' => 'The first item, by id.', 'required' => true],
            ['name' => 'second', 'description' => 'The second item, by id.', 'required' => true],
        ];
    }

    public function getMessages(array $arguments, Context $context): PromptResult
    {
        foreach (['first', 'second'] as $name) {
            if (!ctype_digit($arguments[$name])) {
                throw new PromptException(sprintf('%s must be an item id, such as 12.', $name));
            }
        }
        return PromptResult::text(sprintf('Compare items %s and %s.', $arguments['first'], $arguments['second']));
    }
}

/**
 * A prompt as another module might add, which reads an item through the API
 * and lets the API's exceptions through.
 */
class ItemPrompt implements PromptInterface
{
    protected $api;

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    public function getTitle(): string
    {
        return 'Describe an item';
    }

    public function getDescription(): string
    {
        return 'Describe one item.';
    }

    public function getArguments(): array
    {
        return [['name' => 'id', 'description' => 'The item, by id.', 'required' => true]];
    }

    public function getMessages(array $arguments, Context $context): PromptResult
    {
        $item = $this->api->read('items', (int) $arguments['id'])->getContent();
        return PromptResult::text(sprintf('Describe "%s".', $item->displayTitle()));
    }
}
