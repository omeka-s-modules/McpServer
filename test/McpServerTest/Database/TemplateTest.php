<?php
namespace McpServerTest\Database;

use McpServerTest\DbTestCase;

/**
 * Checks resource templates: the overview's list, get_resource_template, the
 * "template" filter, and the template a full read names.
 */
class TemplateTest extends DbTestCase
{
    protected $ids = [];

    /**
     * A template for letters, of class Text, whose title is required and
     * relabeled, with a comment, and whose date starts private, and three
     * items: a public one and a private one using it, and a public one that
     * doesn't.
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->adminUser());
        $api = $this->api();
        $this->ids['template'] = $api->create('resource_templates', [
            'o:label' => 'Letter template',
            'o:resource_class' => ['o:id' => self::classId('dctype:Text')],
            'o:resource_template_property' => [
                [
                    'o:property' => ['o:id' => self::propertyId('dcterms:title')],
                    'o:alternate_label' => 'Heading',
                    'o:alternate_comment' => 'What the letter is called.',
                    'o:is_required' => true,
                    'o:data_type' => ['literal'],
                ],
                ['o:property' => ['o:id' => self::propertyId('dcterms:date')], 'o:is_private' => true],
            ],
        ])->getContent()->id();
        $template = ['o:resource_template' => ['o:id' => $this->ids['template']]];
        $title = fn ($text) => ['dcterms:title' => [self::literal('dcterms:title', $text)]];
        $this->ids['letter'] = $api->create('items', $template + $title('A letter'))->getContent()->id();
        $this->ids['private_letter'] = $api->create('items', $template + $title('A private letter') + ['o:is_public' => false])->getContent()->id();
        $this->ids['other'] = $api->create('items', $title('Not a letter'))->getContent()->id();
    }

    public function testOverviewListsTheTemplatesItemsUseWithWhatTheCallerMaySee()
    {
        $list = fn ($caller) => array_column($this->resultData($this->callTool('get_overview', ['list' => 'templates'], $caller))['entries'], null, 'label');
        $this->assertSame(['id' => $this->ids['template'], 'label' => 'Letter template', 'class' => 'dctype:Text', 'items' => 1], $list(null)['Letter template']);
        $this->assertSame(2, $list($this->adminUser())['Letter template']['items']);
    }

    public function testGetResourceTemplate()
    {
        $template = $this->resultData($this->callTool('get_resource_template', ['id' => $this->ids['template']]));
        $this->assertSame(['Letter template', 'dctype:Text', 1], [$template['label'], $template['resource_class']['term'], $template['items']]);
        $this->assertSame([
            ['term' => 'dcterms:title', 'label' => 'Heading', 'comment' => 'What the letter is called.', 'required' => true, 'data_types' => ['literal']],
            ['term' => 'dcterms:date', 'label' => 'Date', 'private_by_default' => true],
        ], $template['properties']);
        $result = $this->callTool('get_resource_template', ['id' => 999999999]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('No resource template with id 999999999', $this->resultText($result));
    }

    public function testTemplateFilter()
    {
        $found = fn ($arguments) => array_column($this->resultData($this->callTool('search_items', $arguments))['items'], 'id');
        $this->assertSame([$this->ids['letter']], $found(['template' => 'Letter template']));
        // A label matches regardless of case.
        $this->assertSame([$this->ids['letter']], $found(['template' => 'letter TEMPLATE']));
        $this->assertSame(1, $this->resultData($this->callTool('count_properties', ['template' => 'Letter template']))['matching_items']);
        $result = $this->callTool('search_items', ['template' => 'Nope']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('There is no resource template "Nope". The templates are: ', $this->resultText($result));
        $this->assertStringContainsString('Letter template', $this->resultText($result));
    }

    public function testFullReadNamesItsTemplate()
    {
        $item = $this->resultData($this->callTool('get_item', ['id' => $this->ids['letter']]));
        $this->assertSame(['id' => $this->ids['template'], 'label' => 'Letter template'], $item['resource_template']);
        $this->assertArrayNotHasKey('resource_template', $this->resultData($this->callTool('get_item', ['id' => $this->ids['other']])));
    }
}
