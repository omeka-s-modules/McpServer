<?php
namespace McpServer\Mcp\Tool;

/**
 * An MCP tool.
 *
 * Register a tool under the "mcp_server" => "tools" key of a module's config,
 * like any Omeka plugin. The registered name is the name clients see, so it
 * must match ^[a-zA-Z0-9_-]{1,64}$; a tool whose name doesn't is left out,
 * with a warning in Omeka's log. The name should never change once released:
 * clients remember approvals by name. A new ability gets a new tool, never a
 * renamed one.
 *
 * Names:
 *
 * - This module's tools are <verb>_<resource>, after Omeka's API resource
 *   names: "search_" and the plural for many, "get_" and the singular for one,
 *   such as "search_items" and "get_item". Tools that count use "count_",
 *   such as "count_values". The overview, "get_overview", is the one
 *   exception.
 *   Names without a module prefix are this module's.
 * - Name tools from other modules <module>_<verb>_<noun>, such as
 *   "mymodule_search_things".
 * - Don't start a name with "omeka_": clients already show each tool under
 *   the server's name.
 *
 * Rules for tools:
 *
 * - Show a caller nothing Omeka's API wouldn't show them. The API manager
 *   applies the caller's permissions and hides what they may not see, so
 *   read through it wherever it can answer. A caller is anonymous, or signed
 *   in with an MCP key, and the same calls show each one what their account
 *   allows.
 * - Where the API can't answer, as for grouped counts, get the IDs of the
 *   records in question from the API, then query the entity manager only for
 *   rows tied to those IDs. Core's Doctrine filters hide private values, but
 *   not the public values of a private record; limiting the query to those
 *   IDs keeps them out. A check that matches one of the API's own filters,
 *   such as whether a property is in use, reveals nothing more. This
 *   module's internal ResourceCounter is an example.
 * - Never use raw SQL, which core's Doctrine filters don't reach.
 * - Never list or count media through the entity manager except by item IDs
 *   the API returned, because core hides the media of private items in the
 *   API, not in its Doctrine filters. Reading a linked media's title is fine,
 *   since core's own pages show it.
 * - Keep no state between calls. Each call is a separate HTTP request.
 * - Keep a tool's title, description, schema, and annotations the same for
 *   every caller. Clients and caches share the tool list, which is answered
 *   once for everyone.
 * - Change nothing. The endpoint denies every caller the API's create,
 *   update, and delete, and nothing may write in their place: no jobs,
 *   settings, or entity manager.
 * - Write titles, descriptions, and error messages for a model: say what the
 *   tool does, when to use it, and how to fix a bad call.
 * - Return data with ToolResult::json(), and put guidance for the model, such
 *   as why nothing matched, in its note, never in the data. Markers that
 *   describe the data, such as a total for a cut list, belong in the data.
 * - When a tool caps or cuts its output, mark it in the data, and in the note
 *   say how to reach the rest, such as an argument that pages through it,
 *   or say there's no way to. Nothing should be cut silently.
 *
 * Abilities added later, such as structured output, will be optional
 * interfaces a tool can also implement. This interface won't gain methods.
 */
interface ToolInterface
{
    /**
     * Get a short human-readable name, such as "Search items".
     */
    public function getTitle(): string;

    /**
     * Get what the tool does and when to use it, written for a model.
     */
    public function getDescription(): string;

    /**
     * Get the JSON Schema of the arguments, as a PHP array.
     *
     * The root must be an object schema: ['type' => 'object', ...]. With
     * 'additionalProperties' => false, a call with an argument the schema
     * doesn't name is refused, so a misspelled argument can't pass unnoticed.
     */
    public function getInputSchema(): array;

    /**
     * Get behavior hints for clients, which use them to decide whether to ask
     * before calling.
     *
     * Return ToolAnnotations::READ_ONLY for a tool that reads only this
     * installation. Keys: readOnlyHint, destructiveHint, idempotentHint,
     * openWorldHint. A hint left out takes MCP's default, which assumes the
     * worst: that the tool changes things and reaches outside.
     */
    public function getAnnotations(): array;

    /**
     * Run the tool.
     *
     * @param array $arguments The arguments the client sent, not yet validated;
     *     ArgumentReader helps
     * @throws ToolException For problems the model should see, such as a bad
     *     argument. Omeka's API exceptions, such as a record that doesn't exist
     *     or isn't visible, become error results too, so a tool needn't catch
     *     them. Anything else is logged, and the model sees a generic error.
     */
    public function call(array $arguments, Context $context): ToolResult;
}
