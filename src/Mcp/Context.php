<?php
namespace McpServer\Mcp;

/**
 * What a tool or prompt knows about the call it's answering, apart from its
 * arguments: for now, the protocol version.
 *
 * The server builds this, and tools and prompts only read it. New details,
 * such as who is calling, should one need it, or the site of a per-site
 * endpoint, will arrive as new methods.
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
     * Get the MCP protocol version of this request, such as "2026-07-28".
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }
}
