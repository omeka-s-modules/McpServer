<?php
namespace McpServerTest\Database;

use McpServerTest\DbTestCase;

/**
 * Checks value annotations, the values Omeka lets a value carry about itself,
 * such as how certain a date is: get_item shows them with their value, and a
 * property used only in annotations, which searches and counts can't reach,
 * gets a note saying where to read it.
 */
class AnnotationTest extends DbTestCase
{
    protected $itemId;

    /**
     * An item dated 1776, the date annotated "approximate" with bibo:status,
     * which no item, media, or item set uses itself.
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->adminUser());
        $this->itemId = $this->api()->create('items', [
            'dcterms:title' => [self::literal('dcterms:title', 'A letter')],
            'dcterms:date' => [self::literal('dcterms:date', '1776', ['@annotation' => [
                'bibo:status' => [self::literal('bibo:status', 'approximate')],
            ]])],
        ])->getContent()->id();
    }

    public function testGetItemShowsAnnotationsWithTheirValue()
    {
        $date = $this->resultData($this->callTool('get_item', ['id' => $this->itemId]))['values']['dcterms:date']['values'][0];
        $this->assertSame('1776', $date['text']);
        $this->assertSame('approximate', $date['annotations']['bibo:status']['values'][0]['text']);
    }

    public function testAPropertyUsedOnlyInAnnotationsSaysWhereToReadIt()
    {
        $result = $this->callTool('search_items', ['where' => [['property' => 'bibo:status', 'op' => 'equals', 'value' => 'approximate']]]);
        $this->assertSame(0, $this->resultData($result)['total_results']);
        $this->assertStringContainsString('bibo:status is used only in value annotations', $this->resultText($result));
        $result = $this->callTool('count_values', ['property' => 'bibo:status']);
        $this->assertStringContainsString('it\'s used in annotations on their dcterms:date values', $this->resultText($result));
    }
}
