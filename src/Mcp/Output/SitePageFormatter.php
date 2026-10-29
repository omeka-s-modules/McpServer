<?php
namespace McpServer\Mcp\Output;

use Laminas\Log\LoggerInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Site\BlockLayout\Html;
use Omeka\Site\BlockLayout\Manager as BlockLayoutManager;
use Throwable;

/**
 * Turns site pages into output for a model: each block's layout, its text,
 * its settings, and the items it shows.
 *
 * A block's text is what its layout's getFulltextText() gives, which is how
 * core indexes a page for search, so modules' blocks give whatever text they
 * choose. Core's HTML block is the one exception: it's read as values are,
 * since its own text runs paragraphs together. Core's own index
 * also holds the titles of attached items, private ones included, so the text
 * is read here, and the attachments from the blocks, which core's Doctrine
 * filters leave out when the caller may not see their item or media.
 */
class SitePageFormatter
{
    protected $blockLayouts;

    protected $view;

    protected $logger;

    public function __construct(BlockLayoutManager $blockLayouts, PhpRenderer $view, LoggerInterface $logger)
    {
        $this->blockLayouts = $blockLayouts;
        $this->view = $view;
        $this->logger = $logger;
    }

    /**
     * Get the short form of a page, for search results, with the start of its
     * text.
     */
    public function summary(SitePageRepresentation $page): array
    {
        $texts = array_filter(array_map(fn ($block) => $this->blockText($block), $page->blocks()));
        return OutputLimits::withoutEmpty($this->fields($page) + [
            'excerpt' => $texts ? OutputLimits::shorten(implode("\n", $texts), OutputLimits::DESCRIPTION_LIMIT) : null,
        ]);
    }

    /**
     * Get a page in full, in the form get_site_page returns: its blocks in
     * order, each with its text, cut at OutputLimits::TEXT_LIMIT like a long
     * value, its settings, and the items it shows.
     *
     * Every block is listed, since an editor adds each by hand.
     */
    public function page(SitePageRepresentation $page): array
    {
        $blocks = [];
        foreach ($page->blocks() as $index => $block) {
            $text = $this->blockText($block);
            $dataCut = false;
            $data = $this->blockData($block, $dataCut);
            $blocks[] = OutputLimits::withoutEmpty([
                'position' => $index + 1,
                'layout' => $this->layoutLabel($block),
                ...(null === $text ? [] : OutputLimits::cutAndMark($text)),
                'data' => $data,
                OutputLimits::DATA_TRUNCATED => $dataCut ?: null,
                'items' => $this->attachedItems($block),
            ]);
        }
        return OutputLimits::withoutEmpty($this->fields($page) + $this->dates($page) + ['blocks' => $blocks]);
    }

    /**
     * Get part of a block's text, from a character offset.
     *
     * @return array|null ['length', 'text'], or null for a block with no text
     */
    public function blockChunk(SitePageBlockRepresentation $block, int $offset): ?array
    {
        $text = $this->blockText($block);
        return null === $text ? null : OutputLimits::chunk($text, $offset);
    }

    /**
     * Get the label of a block's layout, as the page editor shows it, such as
     * "HTML", or "Unknown [name]" for a layout whose module is gone.
     */
    public function layoutLabel(SitePageBlockRepresentation $block): string
    {
        return (string) $this->blockLayouts->get($block->layout())->getLabel();
    }

    /**
     * Get when a page was added and last modified, by the names summaries
     * give them.
     */
    public function dates(SitePageRepresentation $page): array
    {
        return [
            'added' => $page->created()->format('c'),
            'modified' => $page->modified() ? $page->modified()->format('c') : null,
        ];
    }

    /**
     * Get the fields every form of a page starts with.
     *
     * A page is marked private when it or its site is, since either keeps it
     * off the public site.
     */
    protected function fields(SitePageRepresentation $page): array
    {
        $site = $page->site();
        return [
            'id' => $page->id(),
            'title' => $page->title(),
            'is_public' => $page->isPublic() && $site->isPublic() ? null : false,
            'site' => $site->slug(),
            'url' => $page->siteUrl($site->slug(), true),
        ];
    }

    /**
     * Get the items a block shows, with the media and caption each shows.
     *
     * An attachment whose item was deleted has none, and core's pages skip
     * it, caption and all, so it's left out here too.
     */
    protected function attachedItems(SitePageBlockRepresentation $block): array
    {
        $items = [];
        foreach ($block->attachments() as $attachment) {
            $item = $attachment->item();
            if (!$item) {
                continue;
            }
            $media = $attachment->media();
            $items[] = OutputLimits::withoutEmpty([
                'id' => $item->id(),
                'title' => $item->displayTitle(),
                'is_public' => $item->isPublic() ? null : false,
                'media' => $media ? OutputLimits::withoutEmpty([
                    'id' => $media->id(),
                    'title' => $media->displayTitle(),
                    'is_public' => $media->isPublic() ? null : false,
                ]) : null,
                'caption' => ValueFormatter::htmlToText((string) $attachment->caption()),
            ]);
        }
        return $items;
    }

    /**
     * Get a block's settings as Omeka stores them, such as a browse preview's
     * search or an asset's caption, without the empty ones.
     *
     * Every layout keeps what an editor enters in its data, so this reads any
     * block, a module's too, without knowing its type. The HTML block's data
     * is its markup, which its text gives. The data is what core's REST API
     * shows anyone who may see the page, references to private resources
     * included, so it reveals no more.
     *
     * @param bool $cut Set to true when a long text or list was cut
     */
    protected function blockData(SitePageBlockRepresentation $block, bool &$cut): array
    {
        if ($this->blockLayouts->get($block->layout()) instanceof Html) {
            return [];
        }
        return OutputLimits::compactData((array) $block->data(), $cut);
    }

    /**
     * Get a block's text as its layout gives it for search, tidied.
     *
     * Returns null when the layout gives none, or fails here, for example one
     * that expects a site.
     */
    protected function blockText(SitePageBlockRepresentation $block): ?string
    {
        $layout = $this->blockLayouts->get($block->layout());
        // Core's HTML block strips its tags without breaking lines, which runs
        // paragraphs and list items together, so its HTML is read as values are.
        if ($layout instanceof Html) {
            return ValueFormatter::htmlToText((string) $block->dataValue('html', ''));
        }
        try {
            $text = $layout->getFulltextText($this->view, $block);
        } catch (Throwable $e) {
            $this->logger->warn(sprintf(
                'MCP Server: could not read the text of a %s block on site page %s: %s',
                $block->layout(), $block->page()->id(), $e->getMessage()
            ));
            return null;
        }
        return ValueFormatter::tidyText((string) $text);
    }
}
