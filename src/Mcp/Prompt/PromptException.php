<?php
namespace McpServer\Mcp\Prompt;

use RuntimeException;

/**
 * A problem with a prompt's arguments the user should see and can fix, such
 * as an item that doesn't exist.
 *
 * Prompts throw this to refuse a call. The message is sent to the client as
 * an invalid-params error, so it should say what went wrong and how to
 * correct it.
 */
class PromptException extends RuntimeException
{
}
