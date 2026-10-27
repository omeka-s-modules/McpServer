<?php
namespace McpServerTest\Database;

use McpServerTest\DbTestCase;
use McpServerTest\Fixtures;

/**
 * Checks that every tool shows each caller only what Omeka lets them see,
 * and marks what's private.
 */
class PrivacyTest extends DbTestCase
{
    protected $fixtures;

    public function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new Fixtures($this->services());
    }

    /**
     * Calls of every tool aimed at the private records: by id, by their text,
     * by their properties, and in counts.
     */
    protected function probes(): array
    {
        $ids = $this->fixtures->ids;
        $contains = ['contains' => 'MCP'];
        $private = [$ids['public_item'], $ids['hidden_item'], $ids['owned_item']];
        return [
            ['get_overview', []],
            ['get_overview', ['list' => 'item_sets']],
            ['get_overview', ['list' => 'templates']],
            ['search_items', []],
            ['search_items', $contains],
            ['search_items', ['properties' => ['dcterms:title', 'dcterms:subject', 'dcterms:relation']]],
            // Only the private value uses it, so it's refused to a visitor.
            ['search_items', ['properties' => ['dcterms:description']]],
            ['search_items', ['where' => [['property' => 'dcterms:subject', 'op' => 'contains', 'value' => 'MCP']]]],
            ['search_items', ['item_set' => [$ids['public_set']]]],
            ['search_items', ['template' => Fixtures::TEMPLATE]],
            ['search_items', ['id' => $private]],
            ['search_items', ['id' => $private, 'properties' => ['dcterms:title', 'dcterms:relation']]],
            ['search_items', ['is_public' => false]],
            ['search_media', []],
            ['search_media', $contains],
            ['search_media', ['item' => $ids['public_item']]],
            ['search_item_sets', []],
            ['search_item_sets', $contains],
            ['get_item', ['id' => $ids['public_item']]],
            ['get_item', ['id' => $ids['public_item'], 'response_format' => 'detailed']],
            ['get_item', ['id' => $ids['public_item'], 'property' => 'dcterms:description']],
            ['get_item', ['id' => $ids['hidden_item']]],
            ['get_item', ['id' => $ids['owned_item']]],
            ['get_media', ['id' => $ids['hidden_media'], 'image' => false]],
            ['get_media', ['id' => $ids['hidden_media'], 'content' => true]],
            ['get_media', ['id' => $ids['public_media'], 'image' => false]],
            ['get_item_set', ['id' => $ids['hidden_set']]],
            ['get_item_set', ['id' => $ids['public_set']]],
            ['count_values', ['property' => 'dcterms:title']],
            ['count_values', ['property' => 'dcterms:subject']],
            ['count_values', ['property' => 'dcterms:description']],
            ['count_values', ['property' => 'dcterms:relation']],
            ['count_values', ['property' => 'dcterms:title', 'resource_type' => 'media']],
            ['count_values', ['property' => 'dcterms:title', 'resource_type' => 'item_sets']],
            ['count_properties', []],
            ['count_properties', ['resource_type' => 'media']],
            ['count_properties', ['template' => Fixtures::TEMPLATE]],
            ['count_values', ['property' => 'dcterms:title', 'id' => $private]],
            ['get_resource_template', ['id' => $ids['template']]],
            ['search_site_pages', []],
            ['search_site_pages', ['site' => Fixtures::SITE]],
            ['search_site_pages', ['item' => $ids['hidden_item']]],
            ['get_site_page', ['id' => $ids['public_page']]],
            ['get_site_page', ['id' => $ids['private_page']]],
            ['get_site_page', ['site' => Fixtures::SITE, 'slug' => 'draft']],
            ['get_site', ['site' => Fixtures::SITE]],
        ];
    }

    /**
     * Assert that no probe shows any of the markers to the caller.
     *
     * @param string[] $markers
     */
    protected function assertNoProbeShows(array $markers, ?string $caller): void
    {
        $user = $caller ? $this->fixtures->users[$caller] : null;
        foreach ($this->probes() as [$name, $arguments]) {
            $text = $this->resultText($this->callTool($name, $arguments, $user));
            // A probe may be refused, as for a record the caller can't see,
            // but a failure or a misspelled argument would check nothing.
            $call = sprintf('%s %s as %s', $name, json_encode($arguments), $caller ?? 'a visitor');
            $this->assertStringNotContainsString('The tool failed', $text, $call);
            $this->assertStringNotContainsString('Unknown argument', $text, $call);
            foreach ($markers as $marker) {
                $this->assertStringNotContainsString($marker, $text, sprintf(
                    '%s %s shows %s to %s', $name, json_encode($arguments), $marker, $caller ?? 'a visitor'
                ));
            }
        }
    }

    public function testEveryToolIsProbed()
    {
        $tools = $this->services()->get('McpServer\ToolManager')->getToolNames();
        $this->assertEqualsCanonicalizing($tools, array_values(array_unique(array_column($this->probes(), 0))));
    }

    public function testVisitorSeesNothingPrivate()
    {
        $this->assertNoProbeShows([Fixtures::HIDDEN, Fixtures::OWNED, Fixtures::PAGE], null);
    }

    public function testResearcherSeesNothingPrivate()
    {
        $this->assertNoProbeShows([Fixtures::HIDDEN, Fixtures::OWNED, Fixtures::PAGE], 'researcher');
    }

    public function testAuthorSeesOnlyTheirOwn()
    {
        $this->assertNoProbeShows([Fixtures::HIDDEN, Fixtures::PAGE], 'author');
        $item = $this->resultData($this->callTool('get_item', ['id' => $this->fixtures->ids['owned_item']], $this->fixtures->users['author']));
        $this->assertSame(false, $item['is_public']);
        $this->assertStringContainsString(Fixtures::OWNED, $item['title']);
    }

    public function testSiteRoleShowsThePrivatePageOnly()
    {
        $this->assertNoProbeShows([Fixtures::HIDDEN, Fixtures::OWNED], 'site_author');
    }

    public function testReviewerSeesPrivateResourcesButNotPrivatePages()
    {
        $this->assertNoProbeShows([Fixtures::PAGE], 'reviewer');
        $reviewer = $this->fixtures->users['reviewer'];
        $ids = $this->fixtures->ids;
        $item = $this->resultData($this->callTool('get_item', ['id' => $ids['hidden_item']], $reviewer));
        $this->assertSame(false, $item['is_public']);
        $item = $this->resultData($this->callTool('get_item', ['id' => $ids['public_item']], $reviewer));
        $this->assertArrayNotHasKey('is_public', $item);
        $description = $item['values']['dcterms:description']['values'][0];
        $this->assertSame([Fixtures::HIDDEN . ' description', false], [$description['text'], $description['is_public']]);
        $this->assertSame([false], array_column($item['media'], 'is_public'));
        $data = $this->resultData($this->callTool('search_items', ['contains' => 'Public item'], $reviewer));
        $this->assertSame(['text' => Fixtures::HIDDEN . ' description', 'is_public' => false], $data['items'][0]['description']);
    }

    public function testRecordsInsideOtherResultsAreMarkedPrivate()
    {
        $reviewer = $this->fixtures->users['reviewer'];
        $ids = $this->fixtures->ids;
        // A public value that links to the private item, so visitors don't
        // see it.
        $item = $this->resultData($this->callTool('get_item', ['id' => $ids['public_item']], $reviewer));
        $this->assertSame(false, $item['values']['dcterms:relation']['values'][0]['is_public']);
        $found = $this->resultData($this->callTool('search_items', ['id' => [$ids['public_item']], 'properties' => ['dcterms:relation']], $reviewer));
        $this->assertSame(false, $found['items'][0]['properties']['dcterms:relation'][0]['is_public']);
        $item = $this->resultData($this->callTool('get_item', ['id' => $ids['hidden_item']], $reviewer));
        $this->assertSame([$ids['hidden_set'] => false], array_column($item['item_sets'], 'is_public', 'id'));
        $sets = $this->resultData($this->callTool('get_overview', ['list' => 'item_sets'], $reviewer))['entries'];
        $this->assertSame([$ids['hidden_set'] => false], array_column($sets, 'is_public', 'id'));
        // A public media of a private item, which core hides with it.
        $media = $this->resultData($this->callTool('get_media', ['id' => $ids['hidden_media'], 'image' => false], $reviewer));
        $this->assertSame([false, false], [$media['is_public'], $media['item']['is_public']]);
    }

    public function testItemInAPrivateSetDoesNotRevealIt()
    {
        $ids = $this->fixtures->ids;
        // A public item put in the private set too, as when staff gather
        // items for an item set not yet public.
        $this->actAs($this->adminUser());
        $this->api()->update('items', $ids['shown_item'], ['o:item_set' => [['o:id' => $ids['hidden_set']]]], [], ['isPartial' => true]);
        $item = $this->resultData($this->callTool('get_item', ['id' => $ids['shown_item']]));
        $this->assertArrayNotHasKey('item_sets', $item);
        $this->actAs(null);
        $counter = $this->services()->get('McpServer\ResourceCounter');
        $this->assertSame([$ids['public_set']], array_keys($counter->countItemsInSets([$ids['hidden_set'], $ids['public_set']])));
        $counter = $this->services()->get('McpServer\ResourceCounter');
        $this->assertSame([$ids['public_set']], array_keys($counter->getGroupedCounts()['item_sets']));
        $this->assertSame([$ids['public_set']], array_keys($counter->countItemsInSets([$ids['hidden_set'], $ids['public_set']])));
    }

    public function testPrivateRecordsAreRefusedAsMissing()
    {
        $ids = $this->fixtures->ids;
        foreach ([['get_item', $ids['hidden_item']], ['get_media', $ids['hidden_media']], ['get_media', $ids['public_media']], ['get_item_set', $ids['hidden_set']]] as [$name, $id]) {
            $result = $this->callTool($name, ['id' => $id]);
            $this->assertTrue($result->isError(), "$name $id");
            $this->assertStringContainsString('exists or is visible to you', $this->resultText($result));
        }
    }

    public function testCountsLeaveOutPrivateContent()
    {
        $overview = $this->resultData($this->callTool('get_overview'));
        $this->assertSame([2, 1], [$overview['items'], $overview['media']]);
        $sets = array_column($overview['item_sets'], 'items', 'title');
        $this->assertSame(['Public item set' => 1], $sets);
        $reviewer = $this->resultData($this->callTool('get_overview', [], $this->fixtures->users['reviewer']));
        $this->assertSame([4, 3], [$reviewer['items'], $reviewer['media']]);
        $subjects = $this->resultData($this->callTool('count_values', ['property' => 'dcterms:subject']));
        $this->assertSame(['Public subject'], array_column($subjects['values'], 'value'));
    }

    public function testPublicItemHidesItsPrivateParts()
    {
        $item = $this->resultData($this->callTool('get_item', ['id' => $this->fixtures->ids['public_item']]));
        $this->assertArrayNotHasKey('dcterms:description', $item['values']);
        $this->assertArrayNotHasKey('dcterms:relation', $item['values']);
        $this->assertArrayNotHasKey('media', $item);
        $result = $this->callTool('search_media', ['item' => $this->fixtures->ids['public_item']]);
        $this->assertStringContainsString('the item has no media you can see', $this->resultText($result));
        // An item the caller can't see is refused, as by id.
        $result = $this->callTool('search_media', ['item' => $this->fixtures->ids['hidden_item']]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('No item with id', $this->resultText($result));
    }
}
