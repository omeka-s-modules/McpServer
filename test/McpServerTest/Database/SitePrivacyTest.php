<?php
namespace McpServerTest\Database;

use McpServerTest\DbTestCase;
use McpServerTest\Fixtures;

/**
 * Checks what site pages, the items they show, and site navigation show each
 * caller.
 */
class SitePrivacyTest extends DbTestCase
{
    protected $fixtures;

    public function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new Fixtures($this->services());
    }

    /**
     * Get the items each block of the public page shows to a caller, by
     * block position.
     */
    protected function shownItems(?string $caller): array
    {
        $user = $caller ? $this->fixtures->users[$caller] : null;
        $page = $this->resultData($this->callTool('get_site_page', ['id' => $this->fixtures->ids['public_page']], $user));
        $items = [];
        foreach ($page['blocks'] as $block) {
            $items[$block['position']] = array_column($block['items'] ?? [], 'caption', 'id');
        }
        return array_filter($items);
    }

    public function testVisitorSeesOnlyAttachmentsWhoseItemAndMediaArePublic()
    {
        $this->assertSame([2 => [$this->fixtures->ids['shown_item'] => 'Public caption']], $this->shownItems(null));
    }

    public function testReviewerSeesEveryAttachment()
    {
        $ids = $this->fixtures->ids;
        $this->assertSame([$ids['shown_item'], $ids['public_item'], $ids['hidden_item']], array_keys($this->shownItems('reviewer')[2]));
    }

    public function testPrivatePageNeedsARoleOnTheSite()
    {
        $id = $this->fixtures->ids['private_page'];
        foreach ([null, 'author', 'reviewer'] as $caller) {
            $result = $this->callTool('get_site_page', ['id' => $id], $caller ? $this->fixtures->users[$caller] : null);
            $this->assertTrue($result->isError(), $caller ?? 'visitor');
        }
        foreach (['site_author', 'admin'] as $caller) {
            $page = $this->resultData($this->callTool('get_site_page', ['site' => Fixtures::SITE, 'slug' => 'draft'], $this->fixtures->users[$caller]));
            $this->assertSame([$id, false], [$page['id'], $page['is_public']], $caller);
        }
    }

    public function testNavigationLeavesOutLinksToPagesTheCallerCantSee()
    {
        $ids = $this->fixtures->ids;
        $site = $this->resultData($this->callTool('get_site', ['site' => Fixtures::SITE]));
        $this->assertSame([['label' => 'Exhibit', 'page_id' => $ids['public_page']]], array_map(
            fn ($link) => array_intersect_key($link, ['label' => true, 'page_id' => true, 'links' => true]),
            $site['navigation']
        ));
        $site = $this->resultData($this->callTool('get_site', ['site' => Fixtures::SITE], $this->fixtures->users['site_author']));
        $child = $site['navigation'][0]['links'][0];
        $this->assertSame([Fixtures::PAGE . ' link', $ids['private_page']], [$child['label'], $child['page_id']]);
    }

    public function testSiteCountsWhatTheCallerMaySee()
    {
        // Omeka gives a new site a page of its own, which the navigation the
        // fixtures set doesn't link.
        $visitor = $this->resultData($this->callTool('get_site', ['site' => Fixtures::SITE]));
        $this->assertSame($visitor['pages'] - 1, $visitor['pages_not_in_navigation']);
        $member = $this->resultData($this->callTool('get_site', ['site' => Fixtures::SITE], $this->fixtures->users['site_author']));
        $this->assertSame([$visitor['pages'] + 1, $visitor['pages_not_in_navigation']], [$member['pages'], $member['pages_not_in_navigation']]);
    }

    public function testSearchByAnItemTheCallerCantSeeIsRefused()
    {
        $id = $this->fixtures->ids['hidden_item'];
        $result = $this->callTool('search_site_pages', ['item' => $id]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('No item with id', $this->resultText($result));
        $found = $this->resultData($this->callTool('search_site_pages', ['item' => $id], $this->fixtures->users['reviewer']));
        $this->assertSame([$this->fixtures->ids['public_page']], array_column($found['site_pages'], 'id'));
    }

    public function testBlockDataGivesSettingsAndTheHtmlBlockGivesText()
    {
        $page = $this->resultData($this->callTool('get_site_page', ['id' => $this->fixtures->ids['public_page']]));
        [$html, , $preview] = $page['blocks'];
        $this->assertSame('Exhibit text.', $html['text']);
        $this->assertArrayNotHasKey('data', $html);
        $this->assertSame('item_set_id[]=' . $this->fixtures->ids['public_set'], $preview['data']['query']);
    }
}
