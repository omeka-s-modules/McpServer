<?php
namespace McpServer\Mcp\Tool;

/**
 * What a tool knows about the call it's answering, apart from its arguments:
 * for now, the protocol version.
 *
 * The server builds this, and tools only read it. New details, such as who is
 * calling once signing in is supported, or the site of a per-site endpoint,
 * will arrive as new methods.
 */
final class Context
{
    private $protocolVersion;

    /**
     * @internal Built by the server for each call.
     */
    public function __construct(string $protocolVersion)
    {
        $this->protocolVersion = $protocolVersion;
    }

    /**
     * Get the MCP protocol version of this request, e.g. "2026-07-28".
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }
}
