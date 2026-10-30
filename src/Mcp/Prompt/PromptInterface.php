<?php
namespace McpServer\Mcp\Prompt;

use McpServer\Mcp\Context;

/**
 * An MCP prompt: a request a user picks in their assistant to start a task,
 * which the model then carries out with the tools.
 *
 * Register a prompt under the "mcp_server" => "prompts" key of a module's
 * config, like any Omeka plugin. The registered name is the name clients
 * show, so it must match ^[a-zA-Z0-9_-]{1,64}$; a prompt whose name doesn't is
 * left out, with a warning in Omeka's log. The name should never change once
 * released: users come to know prompts by name.
 *
 * Names:
 *
 * - This module's prompts are one plain word for the task, such as
 *   "overview" and "research". Names without a module prefix are this
 *   module's.
 * - Name prompts from other modules <module>_<task>, such as
 *   "mymodule_compare".
 *
 * Rules for prompts:
 *
 * - Write for any installation the module may run on: a prompt is offered
 *   to every user, so it can't assume what's there.
 * - Ask for what the tools can do: a prompt is the request, and the tools
 *   apply the caller's permissions. A prompt that reads Omeka's data to write
 *   its text follows the rules for reading in ToolInterface.
 * - Keep no state between calls. Each call is a separate HTTP request.
 * - Keep a prompt's title, description, and arguments the same for every
 *   caller. Clients and caches share the prompt list, which is answered once
 *   for everyone.
 * - Write titles and descriptions for the user who picks the prompt, and its
 *   text for the model that carries it out.
 *
 * Abilities added later, such as messages in the assistant's voice, will be
 * new constructors on PromptResult or optional interfaces a prompt can also
 * implement. This interface won't gain methods.
 */
interface PromptInterface
{
    /**
     * Get a short human-readable name, such as "Research a topic".
     */
    public function getTitle(): string;

    /**
     * Get what the prompt does, written for the user who picks it.
     */
    public function getDescription(): string;

    /**
     * Get the arguments the user fills in.
     *
     * @return array A list of ['name', 'description', 'required'], as MCP
     *   describes a prompt's arguments. The server refuses a call without a
     *   required argument, or with one the list doesn't name, so the prompt
     *   gets only the arguments it declares.
     */
    public function getArguments(): array;

    /**
     * Get the prompt, with its arguments filled in.
     *
     * @param array $arguments The arguments the user gave, by name: strings,
     *   with surrounding space trimmed and every required one present
     * @throws PromptException For an argument the prompt can't use, such as an
     *   id that isn't a number. The client gets its message. Omeka's API
     *   exceptions, such as a record that doesn't exist or isn't visible, are
     *   refused the same way, as for tools, so a prompt needn't catch them.
     *   Anything else is logged, and the client gets an internal error.
     */
    public function getMessages(array $arguments, Context $context): PromptResult;
}
