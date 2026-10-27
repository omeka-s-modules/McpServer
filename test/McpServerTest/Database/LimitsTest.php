<?php
namespace McpServerTest\Database;

use McpServerTest\DbTestCase;

/**
 * Checks that the read tools cut long lists and texts, mark each cut, say in
 * their notes how to reach the rest, and reach it.
 */
class LimitsTest extends DbTestCase
{
    const LONG_TEXT = 'Long text. ';

    const LONG_TEXT_END = 'It ends by naming the color teal.';

    const PAGE_TEXT = 'Page text. ';

    protected $itemId;

    protected $firstMediaId;

    /**
     * Build an item with more values, media, and links than the tools show,
     * and texts longer than they show: 101 subjects, 101 links to itself,
     * which also give it 101 links from other values, a description longer
     * than one read of 10,000 characters, and 51 media, the first with a
     * text longer than the 2,000 characters shown.
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->adminUser());
        $api = $this->api();
        $literal = fn ($term, $text) => self::literal($term, $text);
        $data = [
            'o:is_public' => true,
            'dcterms:title' => [$literal('dcterms:title', 'Item with long lists')],
            'dcterms:subject' => array_map(fn ($n) => $literal('dcterms:subject', "Subject $n"), range(1, 101)),
            'dcterms:description' => [$literal('dcterms:description', str_repeat(self::LONG_TEXT, 1000) . self::LONG_TEXT_END)],
            'o:media' => array_map(fn ($n) => [
                'o:ingester' => 'html',
                'html' => 1 === $n ? '<p>' . str_repeat(self::PAGE_TEXT, 300) . '</p>' : "<p>Page $n</p>",
                'o:is_public' => true,
            ], range(1, 51)),
        ];
        $this->itemId = $api->create('items', $data)->getContent()->id();
        $data['dcterms:relation'] = array_fill(0, 101, ['type' => 'resource:item', 'property_id' => self::propertyId('dcterms:relation'), 'value_resource_id' => $this->itemId]);
        unset($data['o:media']);
        $api->update('items', $this->itemId, $data, [], ['isPartial' => true]);
        $this->firstMediaId = $api->read('items', $this->itemId)->getContent()->media()[0]->id();
    }

    protected function note($result): string
    {
        $content = $result->getContent();
        return $content[1]['text'] ?? '';
    }

    protected function fullDescription(): string
    {
        return str_repeat(self::LONG_TEXT, 1000) . self::LONG_TEXT_END;
    }

    public function testConciseFormCutsListsAndTextsAndSaysHowToReadThem()
    {
        $result = $this->callTool('get_item', ['id' => $this->itemId]);
        $item = $this->resultData($result);
        $this->assertSame([100, 101], [count($item['values']['dcterms:subject']['values']), $item['values']['dcterms:subject']['total']]);
        $this->assertArrayNotHasKey('total', $item['values']['dcterms:title']);
        $this->assertSame([50, 51], [count($item['media']), $item['media_total']]);
        $this->assertTrue($item['values']['dcterms:description']['values'][0]['truncated']);
        $note = $this->note($result);
        $this->assertStringContainsString('"property"', $note);
        $this->assertStringContainsString('dcterms:subject has 101', $note);
        $this->assertStringContainsString(sprintf('search_media with "item" %d', $this->itemId), $note);
        $this->assertStringContainsString('dcterms:description value 1 was cut after its first 2,000 characters', $note);
        $this->assertStringContainsString('{"property":"dcterms:description","position":1,"offset":2000}', $note);
    }

    public function testDetailedFormCutsListsAndSaysHowToReachThem()
    {
        $result = $this->callTool('get_item', ['id' => $this->itemId, 'response_format' => 'detailed']);
        $data = $this->resultData($result);
        $this->assertSame([100, 100], [count($data['dcterms:subject']), count($data['@reverse']['dcterms:relation'])]);
        $note = $this->note($result);
        foreach (['dcterms:subject 101', '@reverse dcterms:relation 101', 'links_to', 'search_items', 'search_media', 'search_item_sets', '"property"', '"offset" 2000'] as $part) {
            $this->assertStringContainsString($part, $note);
        }
    }

    public function testPropertyReadsAllValuesAPageAtATime()
    {
        $result = $this->callTool('get_item', ['id' => $this->itemId, 'property' => 'dcterms:subject']);
        $this->assertCount(100, $this->resultData($result)['values']);
        $this->assertStringContainsString('Call again with "page" 2', $this->note($result));
        $data = $this->resultData($this->callTool('get_item', ['id' => $this->itemId, 'property' => 'dcterms:subject', 'page' => 2]));
        $this->assertSame([101, ['Subject 101']], [$data['total'], array_column($data['values'], 'text')]);
        // "position" alone pages as usual; only "offset" reads one text.
        $data = $this->resultData($this->callTool('get_item', ['id' => $this->itemId, 'property' => 'dcterms:subject', 'position' => 1]));
        $this->assertCount(100, $data['values']);
    }

    public function testOffsetReadsALongTextToItsEnd()
    {
        $full = $this->fullDescription();
        $first = $this->callTool('get_item', ['id' => $this->itemId, 'property' => 'dcterms:description', 'offset' => 0]);
        $second = $this->callTool('get_item', ['id' => $this->itemId, 'property' => 'dcterms:description', 'offset' => 10000]);
        $one = $this->resultData($first);
        $two = $this->resultData($second);
        $this->assertSame([10000, mb_strlen($full)], [mb_strlen($one['text']), $one['length']]);
        $this->assertSame($full, $one['text'] . $two['text']);
        $this->assertStringContainsString('Call again with "offset" 10000', $this->note($first));
        $this->assertSame('', $this->note($second));
        $past = $this->callTool('get_item', ['id' => $this->itemId, 'property' => 'dcterms:description', 'offset' => 50000]);
        $this->assertSame(sprintf('This text has %s characters, so "offset" 50,000 is past its end.', number_format(mb_strlen($full))), $this->note($past));
        // Defaults sent explicitly aren't contradictions.
        $data = $this->resultData($this->callTool('get_item', ['id' => $this->itemId, 'property' => 'dcterms:description', 'offset' => 0, 'page' => 1, 'position' => 1]));
        $this->assertSame(0, $data['offset']);
    }

    public function testBadReadingCallsAreRefused()
    {
        foreach ([
            [['property' => 'dcterms:description', 'position' => 2], 'at most 1'],
            [['property' => 'dcterms:description', 'offset' => 0, 'page' => 2], '"page"'],
            [['property' => 'dcterms:nope'], 'dcterms:subject'],
            [['property' => 'dcterms:subject', 'response_format' => 'detailed'], 'detailed'],
        ] as [$arguments, $phrase]) {
            $result = $this->callTool('get_item', ['id' => $this->itemId] + $arguments);
            $this->assertTrue($result->isError(), json_encode($arguments));
            $this->assertStringContainsString($phrase, $this->resultText($result));
        }
    }

    public function testMediaContentIsCutAndReadOn()
    {
        $full = trim(str_repeat(self::PAGE_TEXT, 300));
        $result = $this->callTool('get_media', ['id' => $this->firstMediaId, 'image' => false]);
        $this->assertStringContainsString('The content was cut after its first 2,000 characters', $this->note($result));
        $this->assertStringContainsString('{"content":true,"offset":2000}', $this->note($result));
        $data = $this->resultData($this->callTool('get_media', ['id' => $this->firstMediaId, 'content' => true, 'offset' => 2000]));
        $this->assertSame([mb_substr($full, 2000), mb_strlen($full)], [$data['text'], $data['length']]);
        $this->assertStringContainsString('is past its end', $this->note($this->callTool('get_media', ['id' => $this->firstMediaId, 'content' => true, 'offset' => 50000])));
        foreach ([['property' => 'dcterms:title'], ['response_format' => 'detailed'], ['position' => 2]] as $extra) {
            $this->assertTrue($this->callTool('get_media', ['id' => $this->firstMediaId, 'content' => true] + $extra)->isError(), json_encode($extra));
        }
        $data = $this->resultData($this->callTool('get_media', ['id' => $this->firstMediaId, 'content' => true, 'position' => 1, 'page' => 1]));
        $this->assertSame(0, $data['offset']);
    }

    public function testCountValuesMarksAShortenedValueThatStartsWithFinds()
    {
        $where = [['property' => 'dcterms:title', 'op' => 'equals', 'value' => 'Item with long lists']];
        $result = $this->callTool('count_values', ['property' => 'dcterms:description', 'where' => $where]);
        $row = $this->resultData($result)['values'][0];
        $this->assertTrue($row['truncated']);
        $this->assertStringEndsWith('…', $row['value']);
        $this->assertStringContainsString('search_items with a "starts_with" condition', $this->note($result));
        $found = $this->resultData($this->callTool('search_items', ['where' => [['property' => 'dcterms:description', 'op' => 'starts_with', 'value' => mb_substr($row['value'], 0, -1)]]]));
        $this->assertContains($this->itemId, array_column($found['items'], 'id'));
    }
}
