<?php
namespace McpServerTest\Unit;

use Laminas\EventManager\EventManager;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Mock;
use Laminas\Mvc\I18n\DummyTranslator;
use Laminas\ServiceManager\ServiceManager;
use McpServer\Mcp\Context;
use McpServer\Mcp\Tool\Manager;
use McpServer\Mcp\Tool\ToolException;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use McpServer\Mcp\Tool\ToolRunner;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Exception\PermissionDeniedException;
use Omeka\Api\Exception\ValidationException;
use Omeka\Stdlib\ErrorStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ToolRunnerTest extends TestCase
{
    protected $log;

    /**
     * Get a runner for one fake tool, named "fake", whose call does what's
     * given, and a tool whose name clients would reject.
     */
    protected function runner(array $schema, ?callable $call = null): ToolRunner
    {
        $this->log = new Mock;
        $logger = (new Logger)->addWriter($this->log);
        $tool = new FakeTool($schema, $call ?? fn () => ToolResult::json(['ok' => true]));
        // What Omeka's plugin managers ask their container for.
        $services = new ServiceManager;
        $services->setService('Omeka\Logger', $logger);
        $services->setService('EventManager', new EventManager);
        $services->setService('MvcTranslator', new DummyTranslator);
        $tools = new Manager($services, ['factories' => [
            'fake' => fn () => $tool,
            'bad name!' => fn () => $tool,
        ]]);
        return new ToolRunner($tools, $logger);
    }

    protected function runFake(ToolRunner $runner, array $arguments): ToolResult
    {
        return $runner->run('fake', $arguments, new Context('2025-11-25'));
    }

    protected function closedSchema(): array
    {
        return ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false];
    }

    public function testUnknownArgumentsAreRefusedWhenTheSchemaSaysSo()
    {
        $runner = $this->runner($this->closedSchema());
        $this->assertFalse($this->runFake($runner, ['a' => 'x'])->isError());
        $result = $this->runFake($runner, ['b' => 1]);
        $this->assertTrue($result->isError());
        $this->assertSame('Unknown argument "b". fake takes: a.', $result->getContent()[0]['text']);
        $this->assertStringStartsWith('Unknown arguments "b", "c".', $this->runFake($runner, ['b' => 1, 'c' => 2])->getContent()[0]['text']);
    }

    public function testBlankValuesCountAsAbsent()
    {
        $this->assertFalse($this->runFake($this->runner($this->closedSchema()), ['b' => null, 'c' => [], 'd' => '  '])->isError());
    }

    public function testAnOpenSchemaTakesAnyArgument()
    {
        $this->assertFalse($this->runFake($this->runner(['type' => 'object', 'properties' => []]), ['b' => 1])->isError());
    }

    public function testTheToolsOwnAdviceComesFirst()
    {
        // Unknown arguments are checked after the tool runs.
        $runner = $this->runner($this->closedSchema(), function () {
            throw new ToolException('Use "a" instead of "b".');
        });
        $this->assertSame('Use "a" instead of "b".', $this->runFake($runner, ['b' => 1])->getContent()[0]['text']);
    }

    public function testExceptionsBecomeErrorResults()
    {
        $store = new ErrorStore;
        $store->addError('o:title', 'A title is required.');
        $invalid = new ValidationException;
        $invalid->setErrorStore($store);
        foreach ([
            [new ToolException('Bad argument.'), 'Bad argument.'],
            [new NotFoundException, 'The requested resource doesn\'t exist or isn\'t visible to you.'],
            [new PermissionDeniedException, 'You don\'t have permission to do that.'],
            [$invalid, 'The request was rejected: A title is required.'],
        ] as [$exception, $message]) {
            $result = $this->runFake($this->runner($this->closedSchema(), function () use ($exception) {
                throw $exception;
            }), []);
            $this->assertTrue($result->isError(), $message);
            $this->assertSame($message, $result->getContent()[0]['text']);
        }
    }

    public function testAnUnexpectedFailureIsLoggedAndNotShown()
    {
        $runner = $this->runner($this->closedSchema(), function () {
            throw new RuntimeException('Database password is hunter2');
        });
        $result = $this->runFake($runner, []);
        $this->assertSame('The tool failed. The error was logged on the server.', $result->getContent()[0]['text']);
        $this->assertStringContainsString('the tool "fake" failed', $this->log->events[0]['message']);
        $this->assertStringContainsString('hunter2', $this->log->events[0]['message']);
    }

    public function testOnlyRegisteredToolsWithValidNamesRun()
    {
        $runner = $this->runner($this->closedSchema());
        $this->assertTrue($runner->canRun('fake'));
        $this->assertFalse($runner->canRun('bad name!'));
        $this->assertFalse($runner->canRun('missing'));
        $this->assertStringContainsString('skipped the tool "bad name!"', $this->log->events[0]['message']);
    }
}

class FakeTool implements ToolInterface
{
    protected $schema;

    protected $call;

    public function __construct(array $schema, callable $call)
    {
        $this->schema = $schema;
        $this->call = $call;
    }

    public function getTitle(): string
    {
        return 'Fake';
    }

    public function getDescription(): string
    {
        return 'A fake tool.';
    }

    public function getInputSchema(): array
    {
        return $this->schema;
    }

    public function getAnnotations(): array
    {
        return [];
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        return ($this->call)($arguments);
    }
}
