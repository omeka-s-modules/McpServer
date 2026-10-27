<?php
namespace McpServerTest\Database;

use McpServer\Mcp\Tool\Manager;
use McpServer\Mcp\Tool\ToolInterface;
use McpServerTest\DbTestCase;

/**
 * Checks every built-in tool against the rules in ToolInterface that can be
 * checked without knowing the tool.
 */
class ToolContractTest extends DbTestCase
{
    protected function tools(): array
    {
        $manager = $this->services()->get('McpServer\ToolManager');
        $tools = [];
        foreach ($manager->getToolNames() as $name) {
            $tools[$name] = $manager->get($name);
        }
        return $tools;
    }

    public function testEveryToolIsWellFormed()
    {
        foreach ($this->tools() as $name => $tool) {
            $this->assertInstanceOf(ToolInterface::class, $tool, $name);
            $this->assertNotSame('', trim($tool->getTitle()), $name);
            $this->assertNotSame('', trim($tool->getDescription()), $name);
            $schema = $tool->getInputSchema();
            $this->assertSame('object', $schema['type'] ?? null, $name);
            $this->assertIsArray($schema['properties'] ?? null, $name);
            // Tools change nothing, for now.
            $this->assertTrue($tool->getAnnotations()['readOnlyHint'] ?? false, $name);
            if (str_starts_with(get_class($tool), 'McpServer\\')) {
                $this->assertFalse($schema['additionalProperties'] ?? true, "$name refuses unknown arguments");
            }
        }
    }

    public function testEveryRegisteredToolHasANameClientsAccept()
    {
        // Every registered name, since the list of tools offered leaves out,
        // with only a logged warning, a name clients would reject.
        $manager = $this->services()->get('McpServer\ToolManager');
        foreach ($manager->getRegisteredNames() as $name) {
            $this->assertMatchesRegularExpression(Manager::NAME_PATTERN, $name);
        }
    }

    public function testEveryToolIsTheSameForEveryCaller()
    {
        $describe = fn () => array_map(fn ($tool) => [
            $tool->getTitle(), $tool->getDescription(), $tool->getInputSchema(), $tool->getAnnotations(),
        ], $this->tools());
        $this->actAs(null);
        $anonymous = $describe();
        $this->actAs($this->adminUser());
        $this->assertSame($anonymous, $describe());
    }

    public function testEveryToolAnswersACallWithoutArguments()
    {
        // A result or the tool's own error, never a failure, which the
        // runner turns into a generic error.
        foreach (array_keys($this->tools()) as $name) {
            $this->assertStringNotContainsString('The tool failed', $this->resultText($this->callTool($name)), $name);
        }
    }

    public function testUnknownArgumentsAreRefused()
    {
        // Checked after the tool runs, so a tool that needs an argument
        // refuses its absence first. Each built-in tool that answers a call
        // without arguments must refuse the same call with an unknown one.
        foreach ($this->tools() as $name => $tool) {
            if (!str_starts_with(get_class($tool), 'McpServer\\') || $this->callTool($name)->isError()) {
                continue;
            }
            $result = $this->callTool($name, ['no_such_argument' => 1]);
            $this->assertTrue($result->isError(), $name);
            $this->assertStringContainsString('Unknown argument "no_such_argument"', $this->resultText($result), $name);
        }
    }
}
