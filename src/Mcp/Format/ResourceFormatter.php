<?php
namespace McpServer\Mcp\Format;

use McpServer\Mcp\Query\NameLookup;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Settings\Settings;

/**
 * Turns items, item sets, and media into output for a model.
 *
 * The concise forms follow Anthropic's guidance for agent tools: names rather
 * than internal IDs, values as the site shows them, and nothing empty. The
 * JSON-LD form is Omeka's own, for when a model needs everything. The parts
 * that depend on core's representations live in their own classes: values
 * in ValueFormatter, and the JSON-LD and what modules add to a concise form
 * in JsonLdFormatter.
 */
class ResourceFormatter
{
    // Where a summary looks for an item's date, in order.
    const DATE_TERMS = ['dcterms:date', 'dcterms:created', 'dcterms:issued'];

    protected $settings;

    protected $names;

    protected $jsonLdFormatter;

    protected $valueFormatter;

    public function __construct(Settings $settings, NameLookup $names, JsonLdFormatter $jsonLdFormatter, ValueFormatter $valueFormatter)
    {
        $this->settings = $settings;
        $this->names = $names;
        $this->jsonLdFormatter = $jsonLdFormatter;
        $this->valueFormatter = $valueFormatter;
    }

    /**
     * Get the short form of an item, for search results.
     *
     * Reads values with ValueFormatter::valueText(), since its value() would
     * also format their annotations, which a summary leaves out.
     */
    public function itemSummary(ItemRepresentation $item): array
    {
        $date = null;
        foreach (self::DATE_TERMS as $term) {
            $value = $item->value($term);
            if ($value) {
                $date = $this->summaryValue($value, OutputLimits::cut($this->valueFormatter->valueText($value)['text']));
                break;
            }
        }
        $creators = array_values(array_filter(array_map(
            fn ($value) => $this->summaryValue($value, OutputLimits::cut($this->valueFormatter->valueText($value)['text'])),
            $item->value('dcterms:creator', ['all' => true])
        )));
        return OutputLimits::withoutEmpty($this->summaryFields($item) + [
            'date' => $date,
            'creator' => $creators,
        ]);
    }

    /**
     * Get an item in full, in the concise form get_item returns.
     *
     * The summary's description, date, and creator are left out, since the
     * values include them in full.
     */
    public function item(ItemRepresentation $item): array
    {
        $media = $item->media();
        $itemSets = array_map(
            fn ($itemSet) => ['id' => $itemSet->id(), 'title' => $itemSet->displayTitle()],
            $item->itemSets()
        );
        $values = $this->valueFormatter->values($item);
        return OutputLimits::withoutEmpty($this->fullFields($item) + [
            'values' => $values,
            // Media keep the order of the item page.
            'media' => array_map(fn ($media) => $this->mediaEntry($media), array_slice($media, 0, OutputLimits::MEDIA_LIMIT)),
            OutputLimits::MEDIA_TOTAL => count($media) > OutputLimits::MEDIA_LIMIT ? count($media) : null,
            'item_sets' => array_values($itemSets),
            ...$this->jsonLdFormatter->moduleData($item, array_keys($values)),
        ]);
    }

    /**
     * Get the short form of an item set, for search results.
     *
     * Its item count is one query, through the API, so it counts only the
     * items the caller can see.
     */
    public function itemSetSummary(ItemSetRepresentation $itemSet): array
    {
        return OutputLimits::withoutEmpty($this->summaryFields($itemSet) + [
            'items' => $itemSet->itemCount(),
        ]);
    }

    /**
     * Get an item set in full, in the concise form get_item_set returns.
     */
    public function itemSet(ItemSetRepresentation $itemSet): array
    {
        $values = $this->valueFormatter->values($itemSet);
        return OutputLimits::withoutEmpty($this->fullFields($itemSet) + [
            'items' => $itemSet->itemCount(),
            'values' => $values,
            ...$this->jsonLdFormatter->moduleData($itemSet, array_keys($values)),
        ]);
    }

    /**
     * Get the fields every full record starts with, before its own.
     */
    protected function fullFields(AbstractResourceEntityRepresentation $resource): array
    {
        return [
            'id' => $resource->id(),
            'title' => $resource->displayTitle(),
            'is_public' => $this->privateMark($resource),
            'url' => $this->publicUrl($resource),
            'api_url' => $resource->apiUrl(),
            'thumbnail_url' => $resource->thumbnailDisplayUrl('medium'),
            'resource_class' => $this->resourceClass($resource),
            ...$this->dates($resource),
        ];
    }

    /**
     * Get the fields every summary starts with, before its own.
     */
    protected function summaryFields(AbstractResourceEntityRepresentation $resource): array
    {
        return [
            'id' => $resource->id(),
            'title' => $resource->displayTitle(),
            'is_public' => $this->privateMark($resource),
            'resource_class' => $this->resourceClass($resource),
            'description' => $this->description($resource),
            'url' => $this->publicUrl($resource),
            'api_url' => $resource->apiUrl(),
            'thumbnail_url' => $resource->thumbnailDisplayUrl('medium'),
        ];
    }

    /**
     * Get the short form of a result for a search that named fields: what
     * identifies it, and for a media its item, leaving the rest of a summary
     * to the fields named.
     */
    public function briefSummary(AbstractResourceEntityRepresentation $resource): array
    {
        $summary = [
            'id' => $resource->id(),
            'title' => $resource->displayTitle(),
            'is_public' => $this->privateMark($resource),
            'url' => $this->publicUrl($resource),
        ];
        if ($resource instanceof MediaRepresentation) {
            $summary['item'] = $this->mediaItem($resource);
        }
        return OutputLimits::withoutEmpty($summary);
    }

    /**
     * Get the values of the fields a search named, for a summary.
     *
     * Each field lists up to OutputLimits::FIELD_VALUES_LIMIT values as the
     * text get_item shows, without the link or URI it gives beside it, each
     * shortened to a summary's length; a field the resource lacks is an empty
     * list. Cuts are marked beside the fields: each cut field's full length,
     * and whether any value was shortened. Values come from the
     * representation the API returned, so the caller sees only the values
     * they may, and a private one is marked as summaryValue() marks it.
     *
     * @param string[] $terms
     */
    public function fieldValues(AbstractResourceEntityRepresentation $resource, array $terms): array
    {
        $fields = [];
        $totals = [];
        $shortened = false;
        foreach ($terms as $term) {
            $values = $resource->value($term, ['all' => true]);
            $fields[$term] = [];
            foreach (array_slice($values, 0, OutputLimits::FIELD_VALUES_LIMIT) as $value) {
                $text = trim((string) $this->valueFormatter->valueText($value)['text']);
                $shortened = $shortened || mb_strlen($text) > OutputLimits::DESCRIPTION_LIMIT;
                $fields[$term][] = $this->summaryValue($value, OutputLimits::shorten($text, OutputLimits::DESCRIPTION_LIMIT));
            }
            if (count($values) > OutputLimits::FIELD_VALUES_LIMIT) {
                $totals[$term] = count($values);
            }
        }
        $output = ['fields' => $fields];
        if ($totals) {
            $output[OutputLimits::FIELDS_TOTALS] = $totals;
        }
        if ($shortened) {
            $output[OutputLimits::FIELDS_TRUNCATED] = true;
        }
        return $output;
    }

    /**
     * Get a resource's description, shortened for a summary.
     *
     * Reads the same property displayDescription() reads, rendered as
     * ValueFormatter renders it. displayDescription() casts to a string, which
     * outside a site page empties a linked resource and leaves HTML as markup.
     */
    protected function description(AbstractResourceEntityRepresentation $resource): string|array|null
    {
        $template = $resource->resourceTemplate();
        $term = ($template && $template->descriptionProperty())
            ? $template->descriptionProperty()->term()
            : 'dcterms:description';
        $value = $resource->value($term);
        return $value ? $this->summaryValue($value, OutputLimits::shorten($this->valueFormatter->valueText($value)['text'], OutputLimits::DESCRIPTION_LIMIT)) : null;
    }

    /**
     * Get a resource's public page, on a site the caller can see.
     *
     * Omeka's public pages exist only within a site, so this picks a site the
     * resource is on: the default site, when the caller can see it, otherwise
     * the first other site they can, by ID. Null when it's on none.
     *
     * @param ItemRepresentation|ItemSetRepresentation|MediaRepresentation $resource
     */
    public function publicUrl(AbstractResourceEntityRepresentation $resource): ?string
    {
        // The caller's sites, by ID. The resource's own sites aren't filtered
        // by visibility, so they only say which of these it's on.
        $slugs = $this->names->getSiteSlugs();
        // A media is on the sites its item is on.
        $placed = $resource instanceof MediaRepresentation ? $resource->item() : $resource;
        $onSites = array_keys(array_intersect_key($slugs, $placed->sites()));
        if (!$onSites) {
            return null;
        }
        $default = (int) $this->settings->get('default_site');
        $siteId = in_array($default, $onSites, true) ? $default : min($onSites);
        return $resource->siteUrl($slugs[$siteId], true);
    }

    /**
     * Get the date a search sorted by, to add to a result's summary.
     *
     * @param string|null $key "added" or "modified", or null when the search
     *   sorted by something else
     */
    public function sortDate(AbstractResourceEntityRepresentation $resource, ?string $key): array
    {
        return $key ? array_filter([$key => $this->dates($resource)[$key]]) : [];
    }

    /**
     * Get the short form of a media, for search results.
     */
    public function mediaSummary(MediaRepresentation $media): array
    {
        return OutputLimits::withoutEmpty($this->summaryFields($media) + [
            'media_type' => $media->mediaType(),
            'original_url' => $media->originalUrl(),
            'item' => $this->mediaItem($media),
        ]);
    }

    /**
     * Get a media in full, in the concise form get_media returns: the fields
     * an item's full form has, then the media's own.
     */
    public function media(MediaRepresentation $media): array
    {
        $values = $this->valueFormatter->values($media);
        return OutputLimits::withoutEmpty($this->fullFields($media) + [
            'media_type' => $media->mediaType(),
            'original_url' => $media->originalUrl(),
            // The original file name or URL.
            'source' => $media->source(),
            'item' => $this->mediaItem($media),
            'content' => $this->mediaContent($media),
            'values' => $values,
            ...$this->jsonLdFormatter->moduleData($media, array_keys($values)),
        ]);
    }

    /**
     * Get the item a media belongs to, as a media's forms list it.
     */
    protected function mediaItem(MediaRepresentation $media): array
    {
        $item = $media->item();
        return ['id' => $item->id(), 'title' => $item->displayTitle(), 'url' => $this->publicUrl($item)];
    }

    /**
     * Get the text of an HTML media, which holds its text itself rather than
     * in a file, cut at OutputLimits::TEXT_LIMIT like a long value.
     *
     * @return array|null ['text', 'truncated'], or null for other media
     */
    protected function mediaContent(MediaRepresentation $media): ?array
    {
        $text = $this->mediaText($media);
        if (null === $text) {
            return null;
        }
        return OutputLimits::withoutEmpty(['text' => OutputLimits::cut($text), OutputLimits::TRUNCATED => mb_strlen($text) > OutputLimits::TEXT_LIMIT ?: null]);
    }

    /**
     * Get part of a media's content, as ValueFormatter::valueChunk() gets part
     * of a value.
     *
     * @return array|null ['length', 'text'], or null for a media with no text
     *   content
     */
    public function contentChunk(MediaRepresentation $media, int $offset): ?array
    {
        $text = $this->mediaText($media);
        return null === $text ? null : OutputLimits::chunk($text, $offset);
    }

    /**
     * Get the text of an HTML media in full, with tags removed.
     *
     * @return string|null Null for other media, or an HTML media with no text
     */
    protected function mediaText(MediaRepresentation $media): ?string
    {
        if ('html' !== $media->ingester()) {
            return null;
        }
        $html = (string) ($media->mediaData()['html'] ?? '');
        // Core's HTML sanitizer removes these, but admins can turn it off.
        // The pattern doesn't backtrack, so a large block can't exhaust PCRE,
        // and if it fails anyway the text is kept.
        $html = preg_replace('#<(script|style)\b[^>]*+>(?:[^<]++|<(?!/\1\s*>))*+</\1\s*>#i', '', $html) ?? $html;
        // Line breaks where blocks end, so paragraphs don't run together.
        $html = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|tr|blockquote)>#i', "\n", $html);
        $text = trim(preg_replace("/[ \t]+/", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = preg_replace("/\n\s*\n+/", "\n\n", $text);
        return '' === $text ? null : $text;
    }

    /**
     * Get the short entry for a media, as an item lists its media.
     */
    protected function mediaEntry(MediaRepresentation $media): array
    {
        return OutputLimits::withoutEmpty([
            'id' => $media->id(),
            'title' => $media->displayTitle(),
            'is_public' => $this->privateMark($media),
            'media_type' => $media->mediaType(),
            'original_url' => $media->originalUrl(),
            'thumbnail_url' => $media->thumbnailDisplayUrl('medium'),
        ]);
    }

    /**
     * Mark a private resource, which a caller signed in with an MCP key may
     * see, so the model knows it isn't on the public site.
     *
     * @return false|null False for a private resource, or null for a public
     *   one, which leaves the field out
     */
    protected function privateMark(AbstractResourceEntityRepresentation $resource): ?bool
    {
        return $resource->isPublic() ? null : false;
    }

    /**
     * Get a value's text as a summary shows it: the text alone, or with its
     * language and a mark when private, as get_item gives them. A private
     * value is one a caller signed in with an MCP key may see, and the mark
     * tells the model it isn't on the public site.
     *
     * @return string|array The text, or ['text', 'lang', 'is_public' => false]
     *   with whichever of the last two apply
     */
    protected function summaryValue(ValueRepresentation $value, string $text)
    {
        $marks = OutputLimits::withoutEmpty([
            'lang' => $value->lang(),
            'is_public' => $value->isPublic() ? null : false,
        ]);
        return $marks ? ['text' => $text] + $marks : $text;
    }

    protected function resourceClass(AbstractResourceEntityRepresentation $resource): ?array
    {
        $class = $resource->resourceClass();
        return $class ? ['term' => $class->term(), 'label' => $class->label()] : null;
    }

    /**
     * Get when a resource was added and last modified.
     *
     * "Added" rather than "created", which dcterms:created means.
     */
    protected function dates(AbstractResourceEntityRepresentation $resource): array
    {
        return [
            'added' => $resource->created()->format('c'),
            'modified' => $resource->modified() ? $resource->modified()->format('c') : null,
        ];
    }

}
