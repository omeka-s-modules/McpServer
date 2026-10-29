<?php
namespace McpServerTest\Unit;

use McpServer\Mcp\Output\ValueFormatter;
use PHPUnit\Framework\TestCase;

class ValueFormatterTest extends TestCase
{
    public function testHtmlToTextBreaksLinesWhereBlocksEnd()
    {
        $html = '<p>Line one<br>Line two</p><ul><li>Apples</li><li>Pears</li></ul><h2>End</h2>';
        $this->assertSame("Line one\nLine two\nApples\nPears\nEnd", ValueFormatter::htmlToText($html));
    }

    public function testHtmlToTextRemovesScriptsAndStylesAndDecodesEntities()
    {
        $html = '<style>p { color: red }</style><p>Fish &amp; chips&nbsp;&mdash; &lt;cheap&gt;</p><script>alert(1)</script>';
        $this->assertSame("Fish & chips\u{a0}— <cheap>", ValueFormatter::htmlToText($html));
    }

    public function testHtmlToTextCollapsesBlankLines()
    {
        $this->assertSame("One\n\nTwo", ValueFormatter::htmlToText("<p>One</p>\n\n\n\n<p>Two</p>"));
    }

    public function testHtmlToTextGivesNullForNoText()
    {
        $this->assertNull(ValueFormatter::htmlToText('<p> </p><br>'));
    }

    public function testTidyTextTrimsTheSpacesThatStartAndEndALine()
    {
        $this->assertSame("One two\nThree", ValueFormatter::tidyText("  One \t two \n   Three  "));
    }
}
