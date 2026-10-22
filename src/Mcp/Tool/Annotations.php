<?php
namespace McpServer\Mcp\Tool;

/**
 * Common behavior hints for ToolInterface::getAnnotations().
 */
final class Annotations
{
    // A tool that only reads this installation's data.
    const READ_ONLY = [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ];
}
