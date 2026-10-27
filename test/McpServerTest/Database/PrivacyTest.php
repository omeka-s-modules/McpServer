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
        return [
            ['get_overview', []],
            ['get_overview', ['list' => 'item_sets']],
            ['search_items', []],
            ['search_items', $contains],
            ['search_items', ['properties' => ['dcterms:title', 'dcterms:subject', 'dcterms:relation']]],
            // Only the private value uses it, so it's refused to a visitor.
            ['search_items', ['properties' => ['dcterms:description']]],
            ['search_items', ['where' => [['property' => 'dcterms:subject', 'op' => 'contains', 'value' => 'MCP']]]],
            ['search_items', ['item_set' => [$ids['public_set']]]],
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
        $media = $this->resultData($this->callTool('search_media', ['item' => $this->fixtures->ids['hidden_item']]));
        $this->assertSame(0, $media['total_results']);
    }
}
