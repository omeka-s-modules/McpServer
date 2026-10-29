<?php
namespace McpServerTest\Unit;

use McpServer\Entity\McpServerKey;
use PHPUnit\Framework\TestCase;

class McpServerKeyTest extends TestCase
{
    public function testGeneratedKeysAreRecognizableRandomAndSafeInAUrl()
    {
        $keys = array_map(fn () => McpServerKey::generate(), range(1, 100));
        foreach ($keys as $key) {
            // The prefix, then 32 random bytes, in base64 for URLs, without
            // padding.
            $this->assertMatchesRegularExpression('/^omeka_mcp_[A-Za-z0-9_-]{43}$/', $key);
        }
        $this->assertCount(100, array_unique($keys));
    }

    public function testHashIsSha256()
    {
        $this->assertSame(hash('sha256', 'a key'), McpServerKey::hashKey('a key'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', McpServerKey::hashKey('a key'));
    }

    public function testOnlyTheHashIsKept()
    {
        $key = McpServerKey::generate();
        $entity = new McpServerKey;
        $entity->setKey($key);
        // Nothing the entity holds, and so nothing stored, is the key itself.
        $held = serialize($entity);
        $this->assertStringNotContainsString($key, $held);
        $this->assertStringContainsString(McpServerKey::hashKey($key), $held);
    }
}
