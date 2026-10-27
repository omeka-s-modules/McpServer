<?php
namespace McpServerTest\Unit;

use McpServer\Mcp\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolResultTest extends TestCase
{
    public function testJsonComesFirstAndItsNoteSecond()
    {
        $result = ToolResult::json(['a' => 1], 'A note.');
        $this->assertSame([
            ['type' => 'text', 'text' => '{"a":1}'],
            ['type' => 'text', 'text' => 'A note.'],
        ], $result->getContent());
        $this->assertFalse($result->isError());
        $this->assertCount(1, ToolResult::json(['a' => 1])->getContent());
    }

    public function testJsonKeepsTextReadableAndNumbersExact()
    {
        $text = ToolResult::json(['url' => 'https://example.org/a', 'title' => 'Café', 'float' => 1.0, 'bad' => "a\xB1b"])->getContent()[0]['text'];
        $this->assertSame("{\"url\":\"https://example.org/a\",\"title\":\"Café\",\"float\":1.0,\"bad\":\"a\u{FFFD}b\"}", $text);
    }

    public function testTextGivesABlockForEachText()
    {
        $this->assertSame([['type' => 'text', 'text' => 'One'], ['type' => 'text', 'text' => 'Two']], ToolResult::text('One', 'Two')->getContent());
    }

    public function testErrorIsMarked()
    {
        $result = ToolResult::error('Bad call.');
        $this->assertTrue($result->isError());
        $this->assertSame([['type' => 'text', 'text' => 'Bad call.']], $result->getContent());
    }

    public function testImageIsAddedToANewResult()
    {
        $result = ToolResult::json(['a' => 1]);
        $withImage = $result->withImage('bytes', 'image/jpeg');
        $this->assertSame(['type' => 'image', 'data' => base64_encode('bytes'), 'mimeType' => 'image/jpeg'], $withImage->getContent()[1]);
        $this->assertCount(1, $result->getContent());
        $this->assertTrue(ToolResult::error('x')->withImage('bytes', 'image/png')->isError());
    }
}
