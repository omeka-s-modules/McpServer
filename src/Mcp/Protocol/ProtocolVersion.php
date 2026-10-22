<?php
namespace McpServer\Mcp\Protocol;

/**
 * The MCP protocol versions this server speaks.
 */
final class ProtocolVersion
{
    // Served statelessly: each request carries its version in _meta.
    const MODERN = ['2026-07-28'];

    // Negotiated with initialize, newest first.
    const LEGACY = ['2025-11-25', '2025-06-18'];

    // What the spec says to assume when a legacy request has no version
    // header. It isn't supported, because that revision requires servers to
    // accept JSON-RPC batches.
    const LEGACY_DEFAULT = '2025-03-26';

    public static function supported(): array
    {
        return array_merge(self::MODERN, self::LEGACY);
    }

    public static function isModern(string $version): bool
    {
        return in_array($version, self::MODERN, true);
    }

    public static function isLegacy(string $version): bool
    {
        return in_array($version, self::LEGACY, true);
    }

    public static function latestLegacy(): string
    {
        return self::LEGACY[0];
    }
}
