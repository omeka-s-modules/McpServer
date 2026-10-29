<?php
namespace McpServerTest\Unit;

use McpServer\Mcp\Output\OutputLimits;
use PHPUnit\Framework\TestCase;

class OutputLimitsTest extends TestCase
{
    public function testCutKeepsTheLimitAndGivesTheFullLength()
    {
        $this->assertSame('short', OutputLimits::cut('short'));
        $long = str_repeat('é', OutputLimits::TEXT_LIMIT + 5);
        $cut = OutputLimits::cut($long);
        $this->assertStringStartsWith(str_repeat('é', OutputLimits::TEXT_LIMIT) . '…', $cut);
        $this->assertStringEndsWith(sprintf(OutputLimits::CUT_MARKER, OutputLimits::TEXT_LIMIT + 5), $cut);
    }

    public function testShortenStopsAtAWord()
    {
        $this->assertSame('A short text', OutputLimits::shorten('  A short text  ', 50));
        $this->assertSame('The quick brown…', OutputLimits::shorten('The quick brown fox jumps', 18));
    }

    public function testChunkGivesTheLengthAndThePart()
    {
        $this->assertSame(['length' => 10, 'text' => '3456789'], OutputLimits::chunk('0123456789', 3));
        $text = str_repeat('a', OutputLimits::CHUNK_LIMIT + 1);
        $this->assertSame(OutputLimits::CHUNK_LIMIT, mb_strlen(OutputLimits::chunk($text, 0)['text']));
    }

    public function testWithoutEmptyKeepsZeroAndFalse()
    {
        $this->assertSame(['a' => 0, 'b' => false, 'c' => '0'], OutputLimits::withoutEmpty(['a' => 0, 'b' => false, 'c' => '0', 'd' => null, 'e' => '', 'f' => []]));
    }

    public function testCompactDataLeavesOutEmptyFieldsAtEveryLevel()
    {
        $cut = false;
        $data = OutputLimits::compactData(['a' => ['b' => null, 'c' => '', 'd' => []], 'e' => 0, 'f' => [null, 'x'], 'g' => ['h' => 'kept']], $cut);
        $this->assertSame(['e' => 0, 'f' => ['x'], 'g' => ['h' => 'kept']], $data);
        $this->assertFalse($cut);
    }

    public function testCompactDataCutsLongListsAndTexts()
    {
        $cut = false;
        $data = OutputLimits::compactData(['list' => range(1, OutputLimits::LIST_LIMIT + 1)], $cut);
        $this->assertCount(OutputLimits::LIST_LIMIT, $data['list']);
        $this->assertTrue($cut);
        $cut = false;
        $data = OutputLimits::compactData(['nested' => ['text' => str_repeat('a', OutputLimits::TEXT_LIMIT + 1)]], $cut);
        $this->assertStringEndsWith(sprintf(OutputLimits::CUT_MARKER, OutputLimits::TEXT_LIMIT + 1), $data['nested']['text']);
        $this->assertTrue($cut);
    }
}
