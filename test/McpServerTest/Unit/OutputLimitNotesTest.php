<?php
namespace McpServerTest\Unit;

use McpServer\Mcp\Output\OutputLimitNotes;
use McpServer\Mcp\Output\OutputLimits;
use PHPUnit\Framework\TestCase;

class OutputLimitNotesTest extends TestCase
{
    public function testChunkNoteGivesTheNextOffsetOrThatTheOffsetIsPastTheEnd()
    {
        $this->assertStringContainsString('"offset" 4 for the next', OutputLimitNotes::forChunk(['length' => 10, 'text' => '0123'], 0));
        $this->assertNull(OutputLimitNotes::forChunk(['length' => 10, 'text' => '6789'], 6));
        $this->assertSame('This text has 10 characters, so "offset" 12 is past its end.', OutputLimitNotes::forChunk(['length' => 10, 'text' => ''], 12));
    }

    public function testSitePageNoteNamesTheCutBlocks()
    {
        $output = ['blocks' => [
            ['position' => 1, OutputLimits::TRUNCATED => true],
            ['position' => 2],
            ['position' => 3, OutputLimits::TRUNCATED => true, OutputLimits::DATA_TRUNCATED => true],
        ]];
        $note = OutputLimitNotes::forSitePage($output);
        $this->assertStringContainsString('The text of blocks 1, 3 was cut', $note);
        $this->assertStringContainsString('data of block 3 were cut', $note);
        $this->assertNull(OutputLimitNotes::forSitePage(['blocks' => [['position' => 1]]]));
    }

    public function testSearchPropertiesNoteSaysWhatWasCutAndHowToReadIt()
    {
        $this->assertNull(OutputLimitNotes::forSearchProperties([['id' => 1]], 'items'));
        $note = OutputLimitNotes::forSearchProperties([['id' => 1, OutputLimits::PROPERTIES_TOTALS => ['dcterms:subject' => 12]], ['id' => 2]], 'media');
        $this->assertStringContainsString(sprintf('a property lists its first %d values', OutputLimits::PROPERTY_VALUES_LIMIT), $note);
        $this->assertStringNotContainsString('shortened', $note);
        $this->assertStringContainsString('call get_media with "property"', $note);
        $note = OutputLimitNotes::forSearchProperties([['id' => 1, OutputLimits::PROPERTIES_TRUNCATED => true]], 'item_sets');
        $this->assertStringContainsString(sprintf('a value longer than %d characters is shortened', OutputLimits::DESCRIPTION_LIMIT), $note);
        $this->assertStringContainsString('call get_item_set with "property"', $note);
    }

    public function testConciseNoteSaysWhenDataWasCut()
    {
        $this->assertStringContainsString('Long texts in data were cut', OutputLimitNotes::forConcise([OutputLimits::DATA_TRUNCATED => true]));
        $this->assertNull(OutputLimitNotes::forConcise([]));
    }
}
