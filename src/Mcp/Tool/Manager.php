<?php
namespace McpServer\Mcp\Tool;

use McpServer\Mcp\AbstractManager;

class Manager extends AbstractManager
{
    const NOUN = 'tool';

    protected $instanceOf = ToolInterface::class;

    /**
     * Get the names of the tools to offer, in registration order.
     */
    public function getToolNames(): array
    {
        return $this->getOfferedNames();
    }
}
