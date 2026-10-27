<?php
namespace McpServer\Mcp\Tool;

use RuntimeException;

/**
 * A problem the model should see and can act on, such as a bad argument.
 *
 * Tools throw this to return an error result. The message is sent to the
 * model as is, so it should say what went wrong and how to correct it.
 */
class ToolException extends RuntimeException
{
}
