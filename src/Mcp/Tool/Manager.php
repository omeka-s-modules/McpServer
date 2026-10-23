<?php
namespace McpServer\Mcp\Tool;

use Omeka\ServiceManager\AbstractPluginManager;

class Manager extends AbstractPluginManager
{
    // Clients, Claude among them, reject names outside this pattern.
    const NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/D';

    protected $autoAddInvokableClass = false;

    protected $instanceOf = ToolInterface::class;

    protected $toolNames;

    /**
     * Get the names of the tools to offer, in registration order.
     *
     * Only these names can be called. Removing a name during the
     * service.registered_names event therefore disables the tool, which a
     * plain has() check wouldn't honor. The list must be the same for every
     * caller, though: clients cache it as public, so a listener must never
     * filter it by who is calling. Names clients can't use are skipped.
     */
    public function getToolNames(): array
    {
        if (null !== $this->toolNames) {
            return $this->toolNames;
        }
        $this->toolNames = [];
        foreach ($this->getRegisteredNames() as $name) {
            if (!$this->has($name)) {
                continue;
            }
            if (!preg_match(self::NAME_PATTERN, $name)) {
                $this->creationContext->get('Omeka\Logger')->warn(sprintf(
                    'MCP Server: skipped the tool "%s" because clients only accept names matching %s.',
                    $name, self::NAME_PATTERN
                ));
                continue;
            }
            $this->toolNames[] = $name;
        }
        return $this->toolNames;
    }
}
