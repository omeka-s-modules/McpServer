<?php
namespace McpServerTest\Unit;

use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\ToolException;
use PHPUnit\Framework\TestCase;

class ArgumentReaderTest extends TestCase
{
    protected function assertRefused(callable $read, string $message): void
    {
        try {
            $read();
            $this->fail('Not refused: ' . $message);
        } catch (ToolException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testABlankStringCountsAsAbsent()
    {
        $reader = new ArgumentReader(['text' => '  ', 'number' => '', 'list' => null]);
        $this->assertFalse($reader->has('text'));
        $this->assertNull($reader->getText('text'));
        $this->assertSame(5, $reader->getInt('number', 5));
        $this->assertSame([], $reader->getList('list'));
    }

    public function testIntegersAreCheckedAndConvertedOnlyWhenExact()
    {
        $reader = new ArgumentReader(['string' => '42', 'float' => 7.0, 'fraction' => 7.5, 'huge' => '99999999999999999999', 'big' => 1e30, 'word' => 'abc']);
        $this->assertSame(42, $reader->getInt('string'));
        $this->assertSame(7, $reader->getInt('float'));
        foreach (['fraction', 'huge', 'big', 'word'] as $name) {
            $this->assertRefused(fn () => $reader->getInt($name), 'must be an integer');
        }
        $this->assertRefused(fn () => $reader->getInt('string', null, 1, 10), 'from 1 to 10');
        $this->assertRefused(fn () => (new ArgumentReader([]))->requireInt('id'), 'is required');
    }

    public function testBooleansTakeTheirCommonSpellings()
    {
        $reader = new ArgumentReader(['a' => true, 'b' => 'false', 'c' => 1, 'd' => '0', 'e' => 'yes']);
        $this->assertSame([true, false, true, false], [$reader->getBool('a'), $reader->getBool('b'), $reader->getBool('c'), $reader->getBool('d')]);
        $this->assertRefused(fn () => $reader->getBool('e'), 'must be true or false');
    }

    public function testListsAndEnums()
    {
        $reader = new ArgumentReader(['list' => [1, 2, 3], 'object' => ['a' => 1], 'ids' => ['4', 5], 'choice' => 'asc', 'bad' => 'up']);
        $this->assertSame([1, 2, 3], $reader->getList('list'));
        $this->assertRefused(fn () => $reader->getList('object'), 'must be a list');
        $this->assertRefused(fn () => $reader->getList('list', 2), 'at most 2 entries');
        $this->assertSame([4, 5], $reader->getIntList('ids', 1));
        $this->assertSame('asc', $reader->getEnum('choice', ['asc', 'desc']));
        $this->assertRefused(fn () => $reader->getEnum('bad', ['asc', 'desc']), 'must be one of: asc, desc');
    }
}
