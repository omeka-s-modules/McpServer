<?php
namespace McpServer\Mcp\Tool;

/**
 * Sets of behavior hints a tool returns from ToolInterface::getAnnotations(),
 * such as that it only reads.
 */
final class ToolAnnotations
{
    // A tool that only reads this installation's data.
    const READ_ONLY = [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ];
}
