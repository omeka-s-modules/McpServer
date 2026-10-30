<?php
namespace McpServer\Mcp\Prompt;

use McpServer\Mcp\AbstractManager;

class Manager extends AbstractManager
{
    const NOUN = 'prompt';

    protected $instanceOf = PromptInterface::class;

    /**
     * Get the names of the prompts to offer, in registration order.
     */
    public function getPromptNames(): array
    {
        return $this->getOfferedNames();
    }
}
