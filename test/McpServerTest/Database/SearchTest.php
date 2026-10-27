<?php
namespace McpServerTest\Database;

use McpServerTest\DbTestCase;

/**
 * Checks that the searches' filters and sorting, and the counts, give the
 * right answers on records whose answers are known.
 */
class SearchTest extends DbTestCase
{
    protected $ids = [];

    /**
     * Three public items and a private one:
     *
     * - alpha: "Alpha letter", a Text, by Jane Doe, dated 1776-08-22, on
     *   Revolution, in the item set and on the site, with a French
     *   alternative title.
     * - beta: "Beta photo", a Still Image, by John Roe, dated 1850, on the
     *   harbor, on the site, with a media, linking to alpha.
     * - gamma: "Gamma note", no class, by Jane Doe, undated, on Revolution,
     *   whose description holds "zebrafish".
     * - hidden: a private item.
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->adminUser());
        $api = $this->api();
        $literal = fn ($term, $text, $lang = null) => self::literal($term, $text, $lang ? ['@language' => $lang] : []);
        $this->ids['set'] = $api->create('item_sets', ['dcterms:title' => [$literal('dcterms:title', 'Letters set')]])->getContent()->id();
        $this->ids['site'] = $api->create('sites', ['o:title' => 'Search site', 'o:slug' => 'search-site', 'o:theme' => 'default'])->getContent()->id();
        $create = fn (array $data) => $api->create('items', $data)->getContent()->id();
        $this->ids['alpha'] = $create([
            'o:resource_class' => ['o:id' => self::classId('dctype:Text')],
            'o:item_set' => [['o:id' => $this->ids['set']]],
            'o:site' => [['o:id' => $this->ids['site']]],
            'dcterms:title' => [$literal('dcterms:title', 'Alpha letter', 'en')],
            'dcterms:alternative' => [$literal('dcterms:alternative', 'Lettre alpha', 'fr')],
            'dcterms:creator' => [$literal('dcterms:creator', 'Jane Doe')],
            'dcterms:date' => [$literal('dcterms:date', '1776-08-22')],
            'dcterms:subject' => [$literal('dcterms:subject', 'Revolution')],
        ]);
        $this->ids['beta'] = $create([
            'o:resource_class' => ['o:id' => self::classId('dctype:StillImage')],
            'o:site' => [['o:id' => $this->ids['site']]],
            'dcterms:title' => [$literal('dcterms:title', 'Beta photo')],
            'dcterms:creator' => [$literal('dcterms:creator', 'John Roe')],
            'dcterms:date' => [$literal('dcterms:date', '1850')],
            'dcterms:subject' => [$literal('dcterms:subject', 'Harbor')],
            'dcterms:relation' => [['type' => 'resource:item', 'property_id' => self::propertyId('dcterms:relation'), 'value_resource_id' => $this->ids['alpha']]],
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Photo text</p>']],
        ]);
        $this->ids['gamma'] = $create([
            'dcterms:title' => [$literal('dcterms:title', 'Gamma note')],
            'dcterms:creator' => [$literal('dcterms:creator', 'Jane Doe')],
            'dcterms:subject' => [$literal('dcterms:subject', 'Revolution')],
            'dcterms:description' => [$literal('dcterms:description', 'About the zebrafish.')],
        ]);
        $this->ids['hidden'] = $create(['o:is_public' => false, 'dcterms:title' => [$literal('dcterms:title', 'Hidden item')]]);
    }

    /**
     * Get the items a search finds, by name, in the order found.
     */
    protected function found(array $arguments, string $tool = 'search_items', string $list = 'items', bool $asAdmin = false): array
    {
        $data = $this->resultData($this->callTool($tool, $arguments + ['per_page' => 50], $asAdmin ? $this->adminUser() : null));
        $names = array_flip($this->ids);
        return array_map(fn ($result) => $names[$result['id']] ?? $result['id'], $data[$list]);
    }

    protected function where(string $term, string $op, $value = null): array
    {
        return ['where' => [array_filter(['property' => $term, 'op' => $op, 'value' => $value], fn ($v) => null !== $v)]];
    }

    public function testWhereOperators()
    {
        $sort = ['sort' => 'title'];
        $this->assertSame(['alpha', 'gamma'], $this->found($this->where('dcterms:creator', 'equals', 'Jane Doe') + $sort));
        $this->assertSame(['beta'], $this->found($this->where('dcterms:title', 'contains', 'ta ph') + $sort));
        $this->assertSame(['alpha'], $this->found($this->where('dcterms:date', 'starts_with', '17') + $sort));
        $this->assertSame(['gamma'], $this->found($this->where('dcterms:title', 'ends_with', 'note') + $sort));
        $this->assertSame(['alpha', 'beta'], $this->found($this->where('dcterms:date', 'exists') + $sort));
        $this->assertSame(['gamma'], $this->found($this->where('dcterms:date', 'missing') + $sort));
        $this->assertSame(['beta'], $this->found($this->where('dcterms:relation', 'links_to', $this->ids['alpha']) + $sort));
    }

    public function testFilters()
    {
        $sort = ['sort' => 'title'];
        $this->assertSame(['alpha'], $this->found(['class' => 'dctype:Text']));
        $this->assertSame(['beta'], $this->found(['class' => 'Still Image']));
        $this->assertSame(['alpha'], $this->found(['item_set' => [$this->ids['set']]]));
        $this->assertSame(['alpha', 'beta'], $this->found(['site' => 'search-site'] + $sort));
        $this->assertSame(['beta'], $this->found(['has_media' => true]));
        $this->assertSame(['alpha', 'gamma'], $this->found(['has_media' => false] + $sort));
        $this->assertSame(['gamma'], $this->found(['contains' => 'zebra']));
        $this->assertSame(['hidden'], $this->found(['is_public' => false], 'search_items', 'items', true));
        $this->assertSame(['set'], $this->found(['contains' => 'Letters'], 'search_item_sets', 'item_sets'));
        $this->assertCount(1, $this->found(['item' => $this->ids['beta']], 'search_media', 'media'));
    }

    public function testMediaOfAnItemSaysWhyNoneMatched()
    {
        $note = fn ($arguments) => $this->resultText($this->callTool('search_media', $arguments));
        $this->assertStringContainsString('the item has no media you can see', $note(['item' => $this->ids['alpha']]));
        // The item has media, so the other filter left them out.
        $this->assertStringNotContainsString('the item has no media', $note(['item' => $this->ids['beta'], 'contains' => 'zebra']));
    }

    public function testIdFilter()
    {
        $ids = $this->ids;
        $this->assertSame(['alpha', 'gamma'], $this->found(['id' => [$ids['gamma'], $ids['alpha']], 'sort' => 'title']));
        // A private record stays hidden, named or not.
        $this->assertSame(['alpha'], $this->found(['id' => [$ids['hidden'], $ids['alpha']]]));
        $data = $this->resultData($this->callTool('search_items', ['id' => [$ids['alpha'], $ids['beta']], 'sort' => 'title', 'properties' => ['dcterms:creator']]));
        $this->assertSame([['Jane Doe'], ['John Roe']], array_column(array_column($data['items'], 'properties'), 'dcterms:creator'));
        $data = $this->resultData($this->callTool('count_values', ['property' => 'dcterms:subject', 'id' => [$ids['alpha'], $ids['beta']], 'sort' => 'value']));
        $this->assertSame([['value' => 'Harbor', 'items' => 1], ['value' => 'Revolution', 'items' => 1]], $data['values']);
        $this->assertTrue($this->callTool('search_items', ['id' => [0]])->isError());
    }

    public function testSorting()
    {
        $this->assertSame(['alpha', 'beta', 'gamma'], $this->found(['sort' => 'title']));
        $this->assertSame(['gamma', 'beta', 'alpha'], $this->found(['sort' => 'title', 'order' => 'desc']));
        $result = $this->callTool('search_items', ['sort' => 'dcterms:date', 'order' => 'asc']);
        $names = array_flip($this->ids);
        $this->assertSame(['gamma', 'alpha', 'beta'], array_map(fn ($item) => $names[$item['id']], $this->resultData($result)['items']));
        // The one without a date sorts first, and the note says so.
        $this->assertStringContainsString('Only 2 of the 3 matching items have a value for dcterms:date', $this->resultText($result));
    }

    public function testUnknownNamesAndValuesAreRefused()
    {
        foreach ([
            [['class' => 'Nonsense'], 'Nothing uses the class "Nonsense". The classes in use are: '],
            [$this->where('dcterms:nope', 'exists'), 'No values use the property "dcterms:nope"'],
            [['site' => 'nowhere'], 'There is no site "nowhere"'],
            [['contains' => '0'], 'treats it as empty'],
            [['where' => [['property' => 'dcterms:title', 'op' => 'equals', 'value' => 'x', 'extra' => 1]]], 'where[0]: Unknown key "extra"'],
            [['item_set' => [$this->ids['set']]], null],
        ] as [$arguments, $message]) {
            $result = $this->callTool('search_items', $arguments);
            if (null === $message) {
                $this->assertFalse($result->isError());
                continue;
            }
            $this->assertTrue($result->isError(), json_encode($arguments));
            $this->assertStringContainsString($message, $this->resultText($result));
        }
        $this->assertStringContainsString('"item_set" filters only items', $this->resultText($this->callTool('search_media', ['item_set' => [$this->ids['set']]])));
    }

    public function testCountValues()
    {
        $data = $this->resultData($this->callTool('count_values', ['property' => 'dcterms:subject']));
        $this->assertSame([3, 3, 2], [$data['matching_items'], $data['items_with_property'], $data['distinct_values']]);
        $this->assertSame([['value' => 'Revolution', 'items' => 2], ['value' => 'Harbor', 'items' => 1]], $data['values']);
        $data = $this->resultData($this->callTool('count_values', ['property' => 'dcterms:subject', 'sort' => 'value']));
        $this->assertSame(['Harbor', 'Revolution'], array_column($data['values'], 'value'));
        $data = $this->resultData($this->callTool('count_values', ['property' => 'dcterms:creator', 'class' => 'Text']));
        $this->assertSame([['value' => 'Jane Doe', 'items' => 1]], $data['values']);
        $data = $this->resultData($this->callTool('count_values', ['property' => 'dcterms:date', 'prefix_length' => 2, 'sort' => 'value']));
        $this->assertSame([['value' => '17', 'items' => 1], ['value' => '18', 'items' => 1]], $data['values']);
        // A link counts by the linked item's title.
        $data = $this->resultData($this->callTool('count_values', ['property' => 'dcterms:relation']));
        $this->assertSame([['value' => 'Alpha letter', 'items' => 1]], $data['values']);
    }

    public function testCountProperties()
    {
        $data = $this->resultData($this->callTool('count_properties'));
        $this->assertSame(3, $data['matching_items']);
        $counts = array_column($data['properties'], 'items', 'property');
        // Most common first, then by term.
        $this->assertSame(['dcterms:creator' => 3, 'dcterms:subject' => 3, 'dcterms:title' => 3, 'dcterms:date' => 2], array_intersect_key($counts, array_flip(['dcterms:title', 'dcterms:creator', 'dcterms:subject', 'dcterms:date'])));
        $this->assertSame(1, $counts['dcterms:alternative']);
        $languages = array_column($data['properties'], 'languages', 'property');
        $this->assertSame(['fr' => 1], $languages['dcterms:alternative']);
        $data = $this->resultData($this->callTool('count_properties', ['class' => 'Still Image']));
        $this->assertSame(1, $data['matching_items']);
    }
}
