<?php
namespace McpServerTest;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Entity\SitePermission;
use Omeka\Entity\User;

/**
 * Builds the records and users the privacy tests read, through Omeka's API.
 *
 * Every private string carries a marker, so one search of a tool's output
 * finds any leak:
 *
 * - HIDDEN marks what only users who see all private resources may see: a
 *   private item, item set, media, and value, and captions of attachments a
 *   visitor may not see.
 * - OWNED marks the private item the Author owns.
 * - PAGE marks the private site page and its link in the navigation, which
 *   only users with a role on the site may see.
 */
class Fixtures
{
    const HIDDEN = 'MCPHIDDEN';

    const OWNED = 'MCPOWNED';

    const PAGE = 'MCPPAGE';

    const SITE = 'mcp-test';

    /**
     * Users by name: admin (a Global Administrator), reviewer, author (who
     * owns a private item), researcher, and site_author (an Author with a
     * role on the test site).
     *
     * @var User[]
     */
    public $users = [];

    /**
     * Record IDs by name.
     *
     * @var int[]
     */
    public $ids = [];

    protected $services;

    protected $api;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        $this->api = $services->get('Omeka\ApiManager');
        $em = $services->get('Omeka\EntityManager');
        $this->users['admin'] = $em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        DbTestCase::switchUser($services, $this->users['admin']);
        foreach (['reviewer' => 'reviewer', 'author' => 'author', 'researcher' => 'researcher', 'site_author' => 'author'] as $name => $role) {
            $this->users[$name] = DbTestCase::makeUser("mcp-test-$name@example.com", "MCP test $name", $role);
        }
        $this->buildResources();
        $this->buildSite();
    }

    protected function buildResources(): void
    {
        $this->ids['hidden_set'] = $this->create('item_sets', ['o:is_public' => false, 'dcterms:title' => [$this->literal('dcterms:title', self::HIDDEN . ' item set')]]);
        $this->ids['public_set'] = $this->create('item_sets', ['o:is_public' => true, 'dcterms:title' => [$this->literal('dcterms:title', 'Public item set')]]);
        // A private item, in both sets, whose media is public itself but
        // hidden with its item.
        $this->ids['hidden_item'] = $this->create('items', [
            'o:is_public' => false,
            'o:item_set' => [['o:id' => $this->ids['hidden_set']], ['o:id' => $this->ids['public_set']]],
            'dcterms:title' => [$this->literal('dcterms:title', self::HIDDEN . ' item')],
            'dcterms:subject' => [$this->literal('dcterms:subject', self::HIDDEN . ' subject')],
            'o:media' => [$this->htmlMedia(self::HIDDEN . ' media on a private item', true)],
        ]);
        $this->ids['owned_item'] = $this->create('items', [
            'o:is_public' => false,
            'o:owner' => ['o:id' => $this->users['author']->getId()],
            'dcterms:title' => [$this->literal('dcterms:title', self::OWNED . ' item')],
        ]);
        // A public item with a private value, a private media, and a link to
        // the private item, whose title must not show.
        $this->ids['public_item'] = $this->create('items', [
            'o:is_public' => true,
            'o:item_set' => [['o:id' => $this->ids['public_set']]],
            'dcterms:title' => [$this->literal('dcterms:title', 'Public item')],
            'dcterms:description' => [$this->literal('dcterms:description', self::HIDDEN . ' description', false)],
            'dcterms:subject' => [$this->literal('dcterms:subject', 'Public subject')],
            'dcterms:relation' => [['type' => 'resource:item', 'property_id' => DbTestCase::propertyId('dcterms:relation'), 'value_resource_id' => $this->ids['hidden_item']]],
            'o:media' => [$this->htmlMedia(self::HIDDEN . ' private media', false)],
        ]);
        $this->ids['shown_item'] = $this->create('items', [
            'o:is_public' => true,
            'dcterms:title' => [$this->literal('dcterms:title', 'Shown item')],
            'o:media' => [$this->htmlMedia('Shown media text', true)],
        ]);
        foreach (['hidden_item', 'public_item', 'shown_item'] as $name) {
            $this->ids[str_replace('item', 'media', $name)] = $this->api->read('items', $this->ids[$name])->getContent()->primaryMedia()->id();
        }
    }

    /**
     * Build the test site: a public page showing three items, two of which a
     * visitor may not see, and a private page, linked in the navigation
     * under the public one by a label of its own. The Author named
     * site_author has a role on the site.
     */
    protected function buildSite(): void
    {
        $this->ids['site'] = $this->create('sites', ['o:title' => 'MCP test site', 'o:slug' => self::SITE, 'o:theme' => 'default', 'o:is_public' => true]);
        $attachment = fn (string $item, ?string $media, string $caption) => array_filter([
            'o:item' => ['o:id' => $this->ids[$item]],
            'o:media' => $media ? ['o:id' => $this->ids[$media]] : null,
            'o:caption' => "<p>$caption</p>",
        ]);
        $this->ids['public_page'] = $this->create('site_pages', [
            'o:site' => ['o:id' => $this->ids['site']],
            'o:title' => 'Exhibit',
            'o:slug' => 'exhibit',
            'o:is_public' => true,
            'o:block' => [
                ['o:layout' => 'html', 'o:data' => ['html' => '<p>Exhibit text.</p>']],
                ['o:layout' => 'media', 'o:data' => [], 'o:attachment' => [
                    $attachment('shown_item', 'shown_media', 'Public caption'),
                    $attachment('public_item', 'public_media', self::HIDDEN . ' caption on a private media'),
                    $attachment('hidden_item', null, self::HIDDEN . ' caption on a private item'),
                ]],
                ['o:layout' => 'browsePreview', 'o:data' => ['resource_type' => 'items', 'query' => 'item_set_id[]=' . $this->ids['public_set'], 'limit' => 6]],
            ],
        ]);
        $this->ids['private_page'] = $this->create('site_pages', [
            'o:site' => ['o:id' => $this->ids['site']],
            'o:title' => self::PAGE . ' draft',
            'o:slug' => 'draft',
            'o:is_public' => false,
            'o:block' => [['o:layout' => 'html', 'o:data' => ['html' => '<p>' . self::PAGE . ' text</p>']]],
        ]);
        $link = fn (int $id, string $label = '', array $links = []) => ['type' => 'page', 'data' => ['label' => $label, 'id' => $id, 'is_public' => true], 'links' => $links];
        $this->api->update('sites', $this->ids['site'], ['o:navigation' => [
            $link($this->ids['public_page'], '', [$link($this->ids['private_page'], self::PAGE . ' link')]),
        ]], [], ['isPartial' => true]);
        $em = $this->services->get('Omeka\EntityManager');
        $permission = new SitePermission;
        $permission->setSite($em->find(\Omeka\Entity\Site::class, $this->ids['site']));
        $permission->setUser($this->users['site_author']);
        $permission->setRole(SitePermission::ROLE_VIEWER);
        $em->persist($permission);
        $em->flush();
    }

    protected function create(string $resource, array $data): int
    {
        return $this->api->create($resource, $data)->getContent()->id();
    }

    protected function literal(string $term, string $text, bool $isPublic = true): array
    {
        return DbTestCase::literal($term, $text, ['is_public' => $isPublic]);
    }

    protected function htmlMedia(string $text, bool $isPublic): array
    {
        return [
            'o:ingester' => 'html',
            'html' => "<p>$text</p>",
            'o:is_public' => $isPublic,
            'dcterms:title' => [$this->literal('dcterms:title', "$text title")],
        ];
    }
}
