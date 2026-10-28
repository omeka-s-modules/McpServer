<?php
namespace McpServerTest\Database;

use McpServerTest\DbTestCase;
use Omeka\Entity\User;

/**
 * Checks word search, "query", on items and on site pages: what it finds, in
 * what order, for whom, and what it says when it finds nothing.
 *
 * MySQL's full-text index sees only committed rows, so these records are
 * committed before the tests and deleted after them, rather than rolled back
 * with each test.
 */
class FullTextSearchTest extends DbTestCase
{
    protected static $ids = [];

    /**
     * Four items and a site with two pages:
     *
     * - harbour: "Harbour at Collioure", boats painted by Derain.
     * - window: "Open window", onto the harbour.
     * - letters: "Letters home", from the front.
     * - ledger: a private item, a zanzibarite ledger.
     * - public_page, on a mappamundi, and private_page, on a palimpsest.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $services = self::getApplication()->getServiceManager();
        self::switchUser($services, self::admin($services));
        $api = $services->get('Omeka\ApiManager');
        $item = fn (string $title, string $description, bool $isPublic = true) => $api->create('items', [
            'o:is_public' => $isPublic,
            'dcterms:title' => [self::literal('dcterms:title', $title)],
            'dcterms:description' => [self::literal('dcterms:description', $description)],
        ])->getContent()->id();
        self::$ids['harbour'] = $item('Harbour at Collioure', 'Boats in the harbour at dusk, painted by Derain.');
        self::$ids['window'] = $item('Open window', 'A window open onto the harbour.');
        self::$ids['letters'] = $item('Letters home', 'Letters from the front.');
        self::$ids['ledger'] = $item('Ledger', 'A zanzibarite ledger.', false);
        self::$ids['site'] = $api->create('sites', ['o:title' => 'Full-text site', 'o:slug' => 'mcp-fulltext', 'o:theme' => 'default', 'o:is_public' => true])->getContent()->id();
        $page = fn (string $title, string $slug, string $text, bool $isPublic) => $api->create('site_pages', [
            'o:site' => ['o:id' => self::$ids['site']],
            'o:title' => $title,
            'o:slug' => $slug,
            'o:is_public' => $isPublic,
            'o:block' => [['o:layout' => 'html', 'o:data' => ['html' => "<p>$text</p>"]]],
        ])->getContent()->id();
        self::$ids['public_page'] = $page('Old maps', 'old-maps', 'A mappamundi drawn on vellum.', true);
        self::$ids['private_page'] = $page('Draft', 'draft', 'A palimpsest, scraped and written over.', false);
    }

    public static function tearDownAfterClass(): void
    {
        $services = self::getApplication()->getServiceManager();
        self::switchUser($services, self::admin($services));
        $api = $services->get('Omeka\ApiManager');
        // The site's pages go with it.
        $api->delete('sites', self::$ids['site']);
        foreach (['harbour', 'window', 'letters', 'ledger'] as $name) {
            $api->delete('items', self::$ids[$name]);
        }
    }

    protected static function admin($services): User
    {
        return $services->get('Omeka\EntityManager')->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
    }

    /**
     * Get the ids a search finds, in the order found.
     */
    protected function found(string $tool, array $arguments, ?User $caller = null): array
    {
        $list = 'search_site_pages' === $tool ? 'site_pages' : 'items';
        return array_column($this->resultData($this->callTool($tool, $arguments, $caller))[$list], 'id');
    }

    public function testQueryFindsAnyOfTheWordsRankingMoreOfThemFirst()
    {
        $this->assertSame([self::$ids['harbour'], self::$ids['window']], $this->found('search_items', ['query' => 'derain harbour']));
    }

    public function testQueryMatchesWholeWordsOnly()
    {
        $this->assertSame([], $this->found('search_items', ['query' => 'letter']));
        $this->assertSame([self::$ids['letters']], $this->found('search_items', ['query' => 'letters']));
        // "contains" matches part of a word, as the description says.
        $this->assertSame([self::$ids['letters']], $this->found('search_items', ['contains' => 'letter']));
    }

    public function testAQueryOfCommonWordsSaysWhyItFoundNothing()
    {
        $result = $this->callTool('search_items', ['query' => 'the']);
        $this->assertSame(0, $this->resultData($result)['total_results']);
        $this->assertStringContainsString('ignores very common words', $this->resultText($result));
    }

    public function testQueryFindsPrivateItemsOnlyForThoseWhoMaySeeThem()
    {
        $this->assertSame([], $this->found('search_items', ['query' => 'zanzibarite']));
        $data = $this->resultData($this->callTool('search_items', ['query' => 'zanzibarite'], $this->adminUser()));
        $this->assertSame([self::$ids['ledger']], array_column($data['items'], 'id'));
        $this->assertSame(false, $data['items'][0]['is_public']);
    }

    public function testSitePagesAreFoundByTheirText()
    {
        $this->assertSame([self::$ids['public_page']], $this->found('search_site_pages', ['query' => 'mappamundi']));
        $this->assertSame([], $this->found('search_site_pages', ['query' => 'palimpsest']));
        $this->assertSame([self::$ids['private_page']], $this->found('search_site_pages', ['query' => 'palimpsest'], $this->adminUser()));
    }

    public function testRelevanceNeedsAQueryAndListsTheMostRelevantFirst()
    {
        foreach ([
            [['sort' => 'relevance'], 'Sorting by relevance needs a "query".'],
            [['query' => 'harbour', 'order' => 'asc'], 'Relevance always lists the most relevant first.'],
        ] as [$arguments, $message]) {
            $result = $this->callTool('search_items', $arguments);
            $this->assertTrue($result->isError());
            $this->assertStringContainsString($message, $this->resultText($result));
        }
    }
}
