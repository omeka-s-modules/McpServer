<?php
namespace McpServer\Mcp\Tool\Builtin;

use Laminas\Navigation\AbstractContainer;
use Laminas\View\Renderer\PhpRenderer;
use McpServer\Mcp\Output\OutputLimits;
use McpServer\Mcp\Query\IdResolver;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolException;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\Navigation\Link\Fallback;
use Omeka\Site\Navigation\Link\Manager as NavigationLinkManager;

/**
 * Reads one site: what it is, its address, the page visitors land on, how
 * much it holds, and its navigation.
 *
 * The navigation is read as the public site builds its menu, through the
 * site's navigation container, so each link has the label and address
 * visitors see, whatever module adds its type. The stored tree is walked
 * beside it, since only it says which page a link is to.
 */
class GetSite implements ToolInterface
{
    protected $api;

    protected $idResolver;

    protected $navigationLinks;

    protected $view;

    public function __construct(ApiManager $api, IdResolver $idResolver, NavigationLinkManager $navigationLinks, PhpRenderer $view)
    {
        $this->api = $api;
        $this->idResolver = $idResolver;
        $this->navigationLinks = $navigationLinks;
        $this->view = $view;
    }

    public function getTitle(): string
    {
        return 'Get site';
    }

    public function getDescription(): string
    {
        return 'Get one site by its slug, as get_overview lists them: its title, summary, and address; the page '
            . 'visitors land on; how many items, item sets, and pages it has; and its navigation, the menu visitors '
            . 'see, in order, with each page link\'s id for get_site_page. The navigation gives the order of the '
            . 'site\'s pages, and which belong together. search_items, search_item_sets, and search_site_pages '
            . 'with "site" list what the site has.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'site' => [
                    'type' => 'string',
                    'description' => 'The site\'s slug, as get_overview lists it.',
                ],
            ],
            'required' => ['site'],
            'additionalProperties' => false,
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $slug = (new ArgumentReader($arguments))->getText('site');
        if (null === $slug) {
            throw new ToolException('The "site" argument is required.');
        }
        $siteId = $this->idResolver->findSite($slug);
        $site = $this->api->read('sites', $siteId)->getContent();
        // The pages the caller may see, by ID.
        $pages = $site->pages();
        $count = fn ($resource) => $this->api->search($resource, ['site_id' => $siteId, 'limit' => 0])->getTotalResults();
        $output = [
            'slug' => $site->slug(),
            'title' => $site->title(),
            'is_public' => $site->isPublic() ? null : false,
            'url' => $site->siteUrl(null, true),
            'summary' => trim((string) $site->summary()),
            'added' => $site->created()->format('c'),
            'modified' => $site->modified() ? $site->modified()->format('c') : null,
            'landing_page' => $this->landingPage($site, $pages),
            'items' => $count('items'),
            'item_sets' => $count('item_sets'),
            'pages' => $count('site_pages'),
            'pages_not_in_navigation' => count($site->notLinkedPages()),
            'navigation' => $this->links($site->navigation(), $site->publicNav()->getContainer(), $pages),
        ];
        return ToolResult::json(OutputLimits::withoutEmpty($output));
    }

    /**
     * Get the page visitors land on at the site's address, as core picks it:
     * the homepage, or else the first page the navigation links to.
     */
    protected function landingPage(SiteRepresentation $site, array $pages): ?array
    {
        $homepage = $site->homepage();
        $page = ($homepage ? $pages[$homepage->id()] ?? null : null) ?? (current($site->linkedPages()) ?: null);
        return $page ? OutputLimits::withoutEmpty([
            'id' => $page->id(),
            'title' => $page->title(),
            'is_public' => $page->isPublic() ? null : false,
        ]) : null;
    }

    /**
     * Get navigation links, each with its label, type, and address, and a
     * page link with its page's id.
     *
     * A link to a page the caller may not see is left out, since its label
     * may name the page, and so is a link whose type is gone. Their child
     * links take their place.
     *
     * @param array $stored Links as the site stores them
     * @param AbstractContainer $built The same links, built for the public site
     * @param array $pages The pages the caller may see, by ID
     */
    protected function links(array $stored, AbstractContainer $built, array $pages): array
    {
        $links = [];
        $builtLinks = iterator_to_array($built, false);
        foreach (array_values($stored) as $index => $link) {
            $builtLink = $builtLinks[$index];
            $children = $this->links($link['links'] ?? [], $builtLink, $pages);
            $type = $this->navigationLinks->get($link['type']);
            $pageId = 'page' === $link['type'] ? (int) ($link['data']['id'] ?? 0) : null;
            if ($type instanceof Fallback || (null !== $pageId && !isset($pages[$pageId]))) {
                array_push($links, ...$children);
                continue;
            }
            $href = (string) $builtLink->getHref();
            $links[] = OutputLimits::withoutEmpty([
                'label' => (string) $builtLink->getLabel(),
                'type' => (string) $type->getName(),
                'page_id' => $pageId,
                'is_public' => null !== $pageId && !$pages[$pageId]->isPublic() ? false : null,
                'url' => str_starts_with($href, '/') ? $this->view->serverUrl($href) : $href,
                'links' => $children,
            ]);
        }
        return $links;
    }
}
