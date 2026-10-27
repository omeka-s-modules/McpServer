<?php
namespace McpServer\Mcp\Tool;

/**
 * An MCP tool.
 *
 * Register a tool under the "mcp_server" => "tools" key of a module's config,
 * like any Omeka plugin. The registered name is the name clients see, so it
 * must match ^[a-zA-Z0-9_-]{1,64}$ and should never change once released:
 * clients remember approvals by name. A new ability gets a new tool, never a
 * renamed one.
 *
 * Names:
 *
 * - This module's tools are <verb>_<resource>, after Omeka's API resource
 *   names: "search_" and the plural for many, "get_" and the singular for one,
 *   e.g. "search_items" and "get_item". Tools that count use "count_", e.g.
 *   "count_values", and tools that change things will use "create_",
 *   "update_", and "delete_". Names without a module prefix are this
 *   module's.
 * - Name tools from other modules <module>_<verb>_<noun>, e.g.
 *   "mymodule_search_things".
 * - Don't start a name with "omeka_": clients already show each tool under
 *   the server's name.
 *
 * Rules for tools:
 *
 * - Read only through Omeka's API manager, which applies the caller's
 *   permissions and hides what they may not see. A caller is anonymous, or
 *   signed in with an MCP key, and the same calls show each one what their
 *   account allows.
 * - Don't use the entity manager to reach resources, except in two cases,
 *   both kept in the McpServer\ResourceCounter service:
 *   - Counting, which the API can't do. ResourceCounter's grouped counts
 *     take an API resource and query, fetch the matching IDs through the API
 *     themselves, and read only those resources' rows, so the API still
 *     decides what's visible, and core's Doctrine filters still hide private
 *     values.
 *   - Checking whether a property or class is in use, or used only in value
 *     annotations, which the API can't do fast. The check reads a row or
 *     two, and reveals no more than core's "used" filter does, apart from
 *     whether the values are annotations.
 * - Never use raw SQL, which core's Doctrine filters don't reach.
 * - Never list or count media through the entity manager except by item IDs
 *   the API returned, because core hides the media of private items in the
 *   API, not in its Doctrine filters. Reading a linked media's title is fine,
 *   since core's own pages show it.
 * - Keep no state between calls. Each call is a separate HTTP request.
 * - Keep a tool's title, description, schema, and annotations the same for
 *   every caller. Clients and caches share the tool list, which is answered
 *   once for everyone.
 * - Change nothing, for now. The endpoint denies every caller the API's
 *   create, update, and delete, and nothing may write in their place: no
 *   jobs, settings, or entity manager. Tools that change things wait for a
 *   sign-in built for it, such as OAuth.
 * - Write titles, descriptions, and error messages for a model: say what the
 *   tool does, when to use it, and how to fix a bad call.
 * - Return data with ToolResult::json(), and put guidance for the model, such
 *   as why nothing matched, in its note, never in the data. Markers that
 *   describe the data, such as a total for a cut list, belong in the data.
 * - When a tool caps or cuts its output, mark it in the data, and in the note
 *   say how to reach the rest, such as an argument that pages through it,
 *   or say there's no way to. Nothing should be cut silently.
 *
 * Abilities added later, such as structured output or required scopes, will
 * be optional interfaces a tool can also implement. This interface won't gain
 * methods.
 */
interface ToolInterface
{
    /**
     * Get a short human-readable name, e.g. "Search items".
     */
    public function getTitle(): string;

    /**
     * Get what the tool does and when to use it, written for a model.
     */
    public function getDescription(): string;

    /**
     * Get the JSON Schema of the arguments, as a PHP array.
     *
     * The root must be an object schema: ['type' => 'object', ...].
     */
    public function getInputSchema(): array;

    /**
     * Get behavior hints for clients.
     *
     * Keys: readOnlyHint, destructiveHint, idempotentHint, openWorldHint.
     * Clients use these to decide whether to ask before calling.
     */
    public function getAnnotations(): array;

    /**
     * Run the tool.
     *
     * @param array $arguments The arguments the client sent, not yet validated;
     *     ArgumentReader helps
     * @throws \McpServer\Mcp\Exception\ToolException For problems the model
     *     should see, such as a bad argument
     */
    public function call(array $arguments, Context $context): ToolResult;
}
