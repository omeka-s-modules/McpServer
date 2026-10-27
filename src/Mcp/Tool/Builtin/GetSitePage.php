<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Format\OutputLimits;
use McpServer\Mcp\Format\SitePageFormatter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolException;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SitePageRepresentation;

/**
 * Reads one site page: its blocks in order, with their text and the items
 * they show, or one block's long text from an offset.
 */
class GetSitePage implements ToolInterface
{
    protected $api;

    protected $formatter;

    protected $idResolver;

    public function __construct(ApiManager $api, SitePageFormatter $formatter, IdResolver $idResolver)
    {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->idResolver = $idResolver;
    }

    public function getTitle(): string
    {
        return 'Get site page';
    }

    public function getDescription(): string
    {
        return 'Get one site page by its id, as search_site_pages gives it, or by its site and slug, as its address '
            . 'gives them: its title, site, address, and its blocks in order, each with its layout, its text, its '
            . 'settings as Omeka stores them in "data", such as the search a browse preview runs, and the items it '
            . 'shows. Call get_item with an item\'s id to read it in full.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The page\'s id. Give this, or "site" and "slug".',
                ],
                'site' => [
                    'type' => 'string',
                    'description' => 'The site\'s slug, as in a page\'s address: /s/<site>/page/<slug>.',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'The page\'s slug, with "site".',
                ],
                'block' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => sprintf(
                        'Read only this block\'s text, by its position, starting at 1, %s characters at a time from "offset". Use it to read a text the full page cut.',
                        number_format(OutputLimits::CHUNK_LIMIT)
                    ),
                ],
                'offset' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'With "block", read from this character, starting at 0.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $reader = new ArgumentReader($arguments);
        $page = $this->readPage($reader);
        if ($reader->has('block')) {
            return $this->readBlock($page, $reader);
        }
        if ($reader->has('offset')) {
            throw new ToolException('"offset" reads one block\'s text, so it needs "block".');
        }
        $output = $this->formatter->page($page);
        return ToolResult::json($output, OutputLimitNotes::forSitePage($output));
    }

    /**
     * Read the page by id, or by site and slug.
     *
     * @throws ToolException
     */
    protected function readPage(ArgumentReader $reader): SitePageRepresentation
    {
        $id = $reader->getInt('id', null, 1);
        $site = $reader->getText('site');
        $slug = $reader->getText('slug');
        if (null !== $id) {
            if (null !== $site || null !== $slug) {
                throw new ToolException('Give "id", or "site" and "slug", not both.');
            }
            try {
                return $this->api->read('site_pages', $id)->getContent();
            } catch (NotFoundException $e) {
                throw new ToolException(sprintf('No site page with id %d exists or is visible to you.', $id));
            }
        }
        if (null === $site || null === $slug) {
            throw new ToolException('Give the page\'s "id", or both "site" and "slug".');
        }
        $siteId = $this->idResolver->findSite($site);
        try {
            return $this->api->read('site_pages', ['site' => $siteId, 'slug' => $slug])->getContent();
        } catch (NotFoundException $e) {
            throw new ToolException(sprintf('The site "%s" has no page "%s" that is visible to you. search_site_pages with "site" lists its pages.', $site, $slug));
        }
    }

    /**
     * Read part of one block's text, from an offset.
     *
     * @throws ToolException
     */
    protected function readBlock(SitePageRepresentation $page, ArgumentReader $reader): ToolResult
    {
        $blocks = $page->blocks();
        $position = $reader->getInt('block', null, 1);
        if ($position > count($blocks)) {
            throw new ToolException(sprintf(
                'This page has %d block%s, so "block" can be at most %d.',
                count($blocks), 1 === count($blocks) ? '' : 's', count($blocks)
            ));
        }
        $block = $blocks[$position - 1];
        $offset = $reader->getInt('offset', 0, 0);
        $chunk = $this->formatter->blockChunk($block, $offset);
        if (null === $chunk) {
            throw new ToolException(sprintf('Block %d, a %s block, has no text.', $position, $this->formatter->layoutLabel($block)));
        }
        $output = [
            'id' => $page->id(),
            'title' => $page->title(),
            'block' => $position,
            'layout' => $this->formatter->layoutLabel($block),
            'offset' => $offset,
            'length' => $chunk['length'],
            'text' => $chunk['text'],
        ];
        return ToolResult::json($output, OutputLimitNotes::forChunk($chunk, $offset));
    }
}
