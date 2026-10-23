<?php
namespace McpServer\Mcp\Protocol;

/**
 * The MCP protocol versions this server speaks, by era.
 */
final class ProtocolVersion
{
    // The envelope era, served by EnvelopeEra: each request carries its
    // version in _meta.
    const ENVELOPE = ['2026-07-28'];

    // The handshake era, served by HandshakeEra: negotiated with initialize,
    // newest first.
    const HANDSHAKE = ['2025-11-25', '2025-06-18'];

    // What the spec says to assume when a handshake-era request has no version
    // header. It isn't supported, because that revision requires servers to
    // accept JSON-RPC batches.
    const HANDSHAKE_DEFAULT = '2025-03-26';

    public static function supported(): array
    {
        return array_merge(self::ENVELOPE, self::HANDSHAKE);
    }

    public static function isEnvelope(string $version): bool
    {
        return in_array($version, self::ENVELOPE, true);
    }

    public static function isHandshake(string $version): bool
    {
        return in_array($version, self::HANDSHAKE, true);
    }

    public static function latestHandshake(): string
    {
        return self::HANDSHAKE[0];
    }
}
