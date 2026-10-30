<?php
namespace McpServer\Mcp;

use Omeka\ServiceManager\AbstractPluginManager;

/**
 * The base of the plugin managers for what the server offers, tools and
 * prompts, which decide the same way which names to offer.
 */
abstract class AbstractManager extends AbstractPluginManager
{
    // Clients, Claude among them, reject names outside this pattern.
    const NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/D';

    // What the manager holds, such as "tool", for log messages.
    const NOUN = '';

    protected $autoAddInvokableClass = false;

    protected $offeredNames;

    /**
     * Get the names to offer, in registration order.
     *
     * Only these names can be used. Removing a name during the
     * service.registered_names event therefore disables what it names, which
     * a plain has() check wouldn't honor. The list must be the same for every
     * caller, though: clients cache it as public, so a listener must never
     * filter it by who is calling. Names clients can't use are skipped, with a
     * warning in Omeka's log.
     */
    protected function getOfferedNames(): array
    {
        if (null !== $this->offeredNames) {
            return $this->offeredNames;
        }
        $this->offeredNames = [];
        foreach ($this->getRegisteredNames() as $name) {
            if (!$this->has($name)) {
                continue;
            }
            if (!preg_match(self::NAME_PATTERN, $name)) {
                $this->creationContext->get('Omeka\Logger')->warn(sprintf(
                    'MCP Server: skipped the %s "%s" because clients only accept names matching %s.',
                    static::NOUN, $name, self::NAME_PATTERN
                ));
                continue;
            }
            $this->offeredNames[] = $name;
        }
        return $this->offeredNames;
    }
}
