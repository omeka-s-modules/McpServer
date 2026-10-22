<?php
namespace McpServer\Stdlib;

use Laminas\Log\LoggerInterface;
use Laminas\View\Renderer\PhpRenderer;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\DataType\Manager as DataTypeManager;
use Omeka\Settings\Settings;
use stdClass;
use Throwable;

/**
 * Turns items, item sets, and media into output for a model.
 *
 * The concise forms follow Anthropic's guidance for agent tools: names rather
 * than internal IDs, values as the site shows them, and nothing empty. The
 * JSON-LD form is Omeka's own, for when a model needs everything. It also
 * finds each resource's public page, on a site the caller may see, for a
 * whole page of search results at once when primePublicUrls() runs first.
 */
class ResourceFormatter
{
    // Longest description in a summary, and longest site summary.
    const DESCRIPTION_LIMIT = 300;

    // Longest text of a single value before it's cut.
    const TEXT_LIMIT = 2000;

    // Most media an item lists; its media_total says when there are more.
    const MEDIA_LIMIT = 50;

    // Most entries of one list, such as a property's values or the links from
    // other items, before it's cut.
    const LIST_LIMIT = 100;

    // Most characters of one text read at a time, from an offset, to read
    // the rest of a text cut at TEXT_LIMIT.
    const CHUNK_LIMIT = 10000;

    // Where a summary looks for an item's date, in order.
    const DATE_TERMS = ['dcterms:date', 'dcterms:created', 'dcterms:issued'];

    protected $api;

    protected $settings;

    protected $options;

    protected $dataTypes;

    protected $view;

    protected $logger;

    // The site each item or item set links to, by resource name and ID, for
    // a page of results primed by primePublicUrls().
    protected $primedSites = [];

    // The visible sites' IDs, the default site first, once per request.
    protected $sitePreference;

    public function __construct(ApiManager $api, Settings $settings, SearchOptions $options, DataTypeManager $dataTypes, PhpRenderer $view, LoggerInterface $logger)
    {
        $this->api = $api;
        $this->settings = $settings;
        $this->options = $options;
        $this->dataTypes = $dataTypes;
        $this->view = $view;
        $this->logger = $logger;
    }

    /**
     * Get the short form of an item, for search results.
     *
     * Reads values with valueText(), since value() would also format their
     * annotations, which a summary leaves out.
     */
    public function itemSummary(ItemRepresentation $item): array
    {
        $date = null;
        foreach (self::DATE_TERMS as $term) {
            $value = $item->value($term);
            if ($value) {
                $date = $this->cut($this->valueText($value)['text']);
                break;
            }
        }
        $creators = array_values(array_filter(array_map(
            fn ($value) => $this->cut($this->valueText($value)['text']),
            $item->value('dcterms:creator', ['all' => true])
        )));
        return $this->withoutEmpty([
            'id' => $item->id(),
            'title' => $item->displayTitle(),
            'resource_class' => $this->resourceClass($item),
            'description' => $this->description($item),
            'date' => $date,
            'creator' => $creators,
            'url' => $this->publicUrl($item),
            'api_url' => $item->apiUrl(),
            'thumbnail_url' => $item->thumbnailDisplayUrl('medium'),
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
        $values = $this->values($item);
        return $this->withoutEmpty([
            'id' => $item->id(),
            'title' => $item->displayTitle(),
            'url' => $this->publicUrl($item),
            'api_url' => $item->apiUrl(),
            'thumbnail_url' => $item->thumbnailDisplayUrl('medium'),
            'resource_class' => $this->resourceClass($item),
            ...$this->dates($item),
            'values' => $values,
            // Media keep the order of the item page.
            'media' => array_map(fn ($media) => $this->mediaEntry($media), array_slice($media, 0, self::MEDIA_LIMIT)),
            'media_total' => count($media),
            'item_sets' => array_values($itemSets),
            ...$this->moduleData($item, array_keys($values)),
        ]);
    }

    /**
     * Get the short form of an item set, for search results.
     *
     * Its item count is one query, through the API, so it counts only the
     * items the caller may see.
     */
    public function itemSetSummary(ItemSetRepresentation $itemSet): array
    {
        return $this->withoutEmpty([
            'id' => $itemSet->id(),
            'title' => $itemSet->displayTitle(),
            'description' => $this->description($itemSet),
            'items' => $itemSet->itemCount(),
            'url' => $this->publicUrl($itemSet),
            'api_url' => $itemSet->apiUrl(),
        ]);
    }

    /**
     * Get an item set in full, in the concise form get_item_set returns.
     */
    public function itemSet(ItemSetRepresentation $itemSet): array
    {
        $values = $this->values($itemSet);
        return $this->withoutEmpty([
            'id' => $itemSet->id(),
            'title' => $itemSet->displayTitle(),
            'url' => $this->publicUrl($itemSet),
            'api_url' => $itemSet->apiUrl(),
            'resource_class' => $this->resourceClass($itemSet),
            ...$this->dates($itemSet),
            'items' => $itemSet->itemCount(),
            'values' => $values,
            ...$this->moduleData($itemSet, array_keys($values)),
        ]);
    }

    /**
     * Get a resource's description, shortened for a summary.
     *
     * Reads the same property displayDescription() reads, rendered as value()
     * does: displayDescription() casts to a string, which on this route
     * empties a linked resource and leaves HTML as markup.
     */
    protected function description(AbstractResourceEntityRepresentation $resource): ?string
    {
        $template = $resource->resourceTemplate();
        $term = ($template && $template->descriptionProperty())
            ? $template->descriptionProperty()->term()
            : 'dcterms:description';
        $value = $resource->value($term);
        return $value ? $this->shorten($this->valueText($value)['text'], self::DESCRIPTION_LIMIT) : null;
    }

    /**
     * Get what other modules add to a resource's JSON-LD, such as map
     * locations.
     *
     * Modules extend the JSON-LD through the rep.resource.json event, so
     * reading it brings their data along without any change on their side.
     * What remains after leaving out JSON-LD syntax, core's own "o:" keys, and
     * the resource's property values is theirs. It's passed on as is, apart
     * from the @context noise, and cut as the JSON-LD form is cut, with
     * module_data_totals giving the full length of each list cut, and
     * module_data_truncated saying whether a text was.
     *
     * @param array $terms The terms of the resource's values, already given
     * @return array The fields: module_data, module_data_totals, and
     *     module_data_truncated
     */
    protected function moduleData(AbstractResourceEntityRepresentation $resource, array $terms): array
    {
        $data = new stdClass;
        foreach (get_object_vars(json_decode(json_encode($resource, ToolResult::JSON_FLAGS))) as $key => $value) {
            if (str_starts_with($key, '@') || str_starts_with($key, 'o:')
                || 'thumbnail_display_urls' === $key || in_array($key, $terms, true)
            ) {
                continue;
            }
            $data->{$key} = $this->withoutContext($value);
        }
        $textsCut = 0;
        $data = $this->cutValues($data, $textsCut);
        $totals = $this->cutLists($data, '');
        return [
            'module_data' => get_object_vars($data),
            'module_data_totals' => $totals,
            'module_data_truncated' => $textsCut ? true : null,
        ];
    }

    /**
     * Remove @context keys from decoded JSON-LD, which only cost tokens here.
     */
    protected function withoutContext($data)
    {
        if (is_array($data)) {
            return array_map(fn ($item) => $this->withoutContext($item), $data);
        }
        if ($data instanceof stdClass) {
            unset($data->{'@context'});
            foreach (get_object_vars($data) as $key => $item) {
                $data->{$key} = $this->withoutContext($item);
            }
        }
        return $data;
    }

    /**
     * Get Omeka's JSON-LD for an item or item set, as the API returns it.
     *
     * Long values and long lists are cut, so that extracted text, a book's
     * pages, or the thousands of items linking to a person can't exceed a
     * client's limit on result size. It includes whatever modules add to the
     * JSON-LD.
     *
     * @return array [JSON-LD as a stdClass, full lengths of the cut lists by
     *   name, how many long texts were cut]
     */
    public function jsonLd(AbstractResourceEntityRepresentation $resource): array
    {
        $textsCut = 0;
        // Decode to objects, so empty maps stay {} when encoded again.
        $jsonLd = $this->cutValues(json_decode(json_encode($resource, ToolResult::JSON_FLAGS)), $textsCut);
        $cut = $this->cutLists($jsonLd, '');
        if (isset($jsonLd->{'@reverse'}) && $jsonLd->{'@reverse'} instanceof stdClass) {
            $cut += $this->cutLists($jsonLd->{'@reverse'}, '@reverse ');
        }
        return [$jsonLd, $cut, $textsCut];
    }

    /**
     * Cut an object's long lists, returning their full lengths by name.
     */
    protected function cutLists(stdClass $object, string $prefix): array
    {
        $cut = [];
        foreach (get_object_vars($object) as $key => $value) {
            if (is_array($value) && count($value) > self::LIST_LIMIT) {
                $object->{$key} = array_slice($value, 0, self::LIST_LIMIT);
                $cut[$prefix . $key] = count($value);
            }
        }
        return $cut;
    }

    /**
     * Get an item's or item set's public page, on a site the caller may see.
     *
     * Prefers the default site, if the resource is on it, then its visible
     * sites in order. Returns null when it's on no site the caller sees. Uses
     * what primePublicUrls() found for the resource, when it ran, and
     * otherwise loads the resource's sites.
     *
     * @param ItemRepresentation|ItemSetRepresentation $resource
     */
    public function publicUrl(AbstractResourceEntityRepresentation $resource): ?string
    {
        $name = $resource->resourceName();
        if (array_key_exists($resource->id(), $this->primedSites[$name] ?? [])) {
            $siteId = $this->primedSites[$name][$resource->id()];
        } else {
            // Not filtered by visibility, unlike the API search of sites.
            $onSites = array_keys($resource->sites());
            $siteId = null;
            foreach ($this->getSitePreference() as $candidate) {
                if (in_array($candidate, $onSites, true)) {
                    $siteId = $candidate;
                    break;
                }
            }
        }
        return null === $siteId ? null : $resource->siteUrl($this->options->getSiteSlugs()[$siteId], true);
    }

    /**
     * Find the public pages of a page of results in a few queries.
     *
     * Rather than loading each result's sites, one search per visible site,
     * default first, asks which of the results are on it. With more visible
     * sites than results, publicUrl() loads each result's sites instead.
     *
     * @param AbstractResourceEntityRepresentation[] $resources Items, item
     *   sets, or media, whose public page is their item's
     */
    public function primePublicUrls(array $resources): void
    {
        $ids = [];
        foreach ($resources as $resource) {
            $target = $resource instanceof MediaRepresentation ? $resource->item() : $resource;
            $ids[$target->resourceName()][$target->id()] = true;
        }
        $siteIds = $this->getSitePreference();
        foreach ($ids as $name => $resourceIds) {
            if (count($siteIds) > count($resourceIds)) {
                continue;
            }
            $unplaced = array_keys($resourceIds);
            foreach ($siteIds as $siteId) {
                if (!$unplaced) {
                    break;
                }
                $onSite = $this->api->search(
                    $name,
                    ['id' => $unplaced, 'site_id' => $siteId],
                    ['returnScalar' => 'id', 'countQuery' => false]
                )->getContent();
                foreach ($onSite as $id) {
                    $this->primedSites[$name][(int) $id] = $siteId;
                }
                $unplaced = array_values(array_diff($unplaced, array_map('intval', $onSite)));
            }
            foreach ($unplaced as $id) {
                $this->primedSites[$name][$id] = null;
            }
        }
    }

    /**
     * Get the date a search sorted by, when it sorted by date added or
     * modified, to add to a result's summary.
     */
    public function sortDate(AbstractResourceEntityRepresentation $resource, array $query): array
    {
        $key = ['created' => 'added', 'modified' => 'modified'][$query['sort_by'] ?? ''] ?? null;
        return $key ? array_filter([$key => $this->dates($resource)[$key]]) : [];
    }

    /**
     * Get the short form of a media, for search results.
     */
    public function mediaSummary(MediaRepresentation $media): array
    {
        $item = $media->item();
        return $this->withoutEmpty($this->mediaEntry($media) + [
            'item' => ['id' => $item->id(), 'title' => $item->displayTitle()],
            'url' => $this->publicUrl($item),
        ]);
    }

    /**
     * Get a media in full, as get_media returns it.
     */
    public function media(MediaRepresentation $media): array
    {
        $item = $media->item();
        return $this->withoutEmpty($this->mediaEntry($media) + [
            'item' => ['id' => $item->id(), 'title' => $item->displayTitle(), 'url' => $this->publicUrl($item)],
            // The original file name or URL.
            'source' => $media->source(),
            'content' => $this->mediaContent($media),
            'values' => $this->values($media),
        ]);
    }

    /**
     * Get the text of a media that holds its content itself, rather than in a
     * file, such as an HTML page, cut like a long value.
     *
     * @return array|null ['text', 'truncated'], or null for other media
     */
    protected function mediaContent(MediaRepresentation $media): ?array
    {
        $text = $this->mediaText($media);
        if (null === $text) {
            return null;
        }
        return $this->withoutEmpty(['text' => $this->cut($text), 'truncated' => mb_strlen($text) > self::TEXT_LIMIT ?: null]);
    }

    /**
     * Get part of a media's content, as valueChunk() gets part of a value.
     *
     * @return array|null ['length', 'text'], or null for a media with no text
     *   content
     */
    public function contentChunk(MediaRepresentation $media, int $offset): ?array
    {
        $text = $this->mediaText($media);
        return null === $text ? null : $this->chunk($text, $offset);
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
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html);
        // Line breaks where blocks end, so paragraphs don't run together.
        $html = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|tr|blockquote)>#i', "\n", $html);
        $text = trim(preg_replace("/[ \t]+/", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = preg_replace("/\n\s*\n+/", "\n\n", $text);
        return '' === $text ? null : $text;
    }

    /**
     * Get one property's values on a resource, a page of LIST_LIMIT at a
     * time, for reading all of a list the full forms cut.
     *
     * @return array|null ['label', 'total', 'values'], or null when the
     *   resource has no values for the property
     */
    public function field(AbstractResourceEntityRepresentation $resource, string $term, int $page): ?array
    {
        $property = $resource->values()[$term] ?? null;
        if (!$property || !$property['values']) {
            return null;
        }
        return [
            'label' => $property['alternate_label'] ?: $property['property']->label(),
            'total' => count($property['values']),
            'values' => array_map(
                fn ($value) => $this->value($value),
                array_slice($property['values'], ($page - 1) * self::LIST_LIMIT, self::LIST_LIMIT)
            ),
        ];
    }

    /**
     * Get part of one value's text, from a character offset, CHUNK_LIMIT
     * characters at a time, for reading the rest of a text cut at TEXT_LIMIT.
     *
     * @param int $position The value's position among the property's, from 1
     * @return array|null ['label', 'length', 'text'], or null when the
     *   resource has no such value
     */
    public function valueChunk(AbstractResourceEntityRepresentation $resource, string $term, int $position, int $offset): ?array
    {
        $property = $resource->values()[$term] ?? null;
        $value = $property['values'][$position - 1] ?? null;
        if (!$value) {
            return null;
        }
        return ['label' => $property['alternate_label'] ?: $property['property']->label()]
            + $this->chunk($this->valueText($value)['text'], $offset);
    }

    /**
     * Get part of a text, from a character offset.
     *
     * @return array ['length', 'text']: the text's full length, and the part
     */
    protected function chunk(string $text, int $offset): array
    {
        return ['length' => mb_strlen($text), 'text' => mb_substr($text, $offset, self::CHUNK_LIMIT)];
    }

    /**
     * Get a resource's values, keyed by term, with the labels the site shows.
     */
    protected function values(AbstractResourceEntityRepresentation $resource): array
    {
        $output = [];
        foreach ($resource->values() as $term => $property) {
            if (!$property['values']) {
                continue;
            }
            $output[$term] = [
                // The resource template's label, when it sets one.
                'label' => $property['alternate_label'] ?: $property['property']->label(),
                'values' => array_map(fn ($value) => $this->value($value), array_slice($property['values'], 0, self::LIST_LIMIT)),
            ];
            if (count($property['values']) > self::LIST_LIMIT) {
                $output[$term]['total'] = count($property['values']);
            }
        }
        return $output;
    }

    /**
     * Get one value as text, with what kind of value it is, its text cut
     * when it's long, and its annotations.
     */
    protected function value(ValueRepresentation $value): array
    {
        $output = $this->valueText($value);
        if (mb_strlen($output['text']) > self::TEXT_LIMIT) {
            $output['text'] = $this->cut($output['text']);
            $output['truncated'] = true;
        }
        // Values about the value, such as how certain a date is. Null when
        // there are none, or the caller can't see them.
        $annotation = $value->valueAnnotation();
        if ($annotation) {
            $output['annotations'] = $this->values($annotation);
        }
        return $this->withoutEmpty($output);
    }

    /**
     * Get one value as text, in full, with what kind of value it is.
     *
     * Doesn't cast the value to a string: on this route a linked resource
     * casts to an empty string, since its site URL doesn't exist outside a
     * site, and a URI casts to its label only. A linked resource is read
     * directly, since core's value JSON would also load its thumbnail and
     * build its URL; other values are read from that JSON.
     */
    protected function valueText(ValueRepresentation $value): array
    {
        $output = ['text' => null];
        $extra = [];
        $linked = $value->valueResource();
        if ($linked) {
            $output['text'] = $linked->displayTitle();
            $extra['resource'] = ['id' => $linked->id(), 'type' => $linked->resourceName()];
        } else {
            $json = $value->jsonSerialize();
            if (isset($json['@id']) && is_string($json['@id'])) {
                $label = $json['o:label'] ?? null;
                $output['text'] = (is_string($label) && '' !== trim($label)) ? $label : $json['@id'];
                if ($output['text'] !== $json['@id']) {
                    $extra['uri'] = $json['@id'];
                }
            } elseif (array_key_exists('@value', $json) && is_scalar($json['@value'])) {
                $stored = (string) $json['@value'];
                if ('literal' === $value->type()) {
                    // A literal shows exactly as stored.
                    $output['text'] = $stored;
                } else {
                    // Other types, such as numeric dates, show a readable
                    // form, with the stored form kept beside it when they
                    // differ.
                    $shown = $this->render($value);
                    $output['text'] = $shown ?? $stored;
                    if (null !== $shown && $shown !== trim($stored)) {
                        $output['value'] = $this->cut($stored);
                    }
                }
            } else {
                $output['text'] = (string) $value;
            }
        }
        $output['type'] = $value->type();
        $output['lang'] = $value->lang();
        return $output + $extra;
    }

    /**
     * Render a value as its data type shows it on the site, as plain text.
     *
     * Renders through the data type rather than asHtml(), which also fires
     * rep.value.html: what other modules add there, such as a link to search
     * for the value, is page decoration, not the value. type() names the data
     * type the value resolved to, so the lookup can't fail. Returns null when
     * the data type can't render here, for example one that expects a site.
     */
    protected function render(ValueRepresentation $value): ?string
    {
        try {
            $html = (string) $this->dataTypes->get($value->type())->render($this->view, $value);
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return '' === $text ? null : $text;
        } catch (Throwable $e) {
            $this->logger->warn(sprintf(
                'MCP Server: could not render a %s value of resource %s: %s',
                $value->type(), $value->resource()->id(), $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Get the short entry for a media, as an item lists its media.
     */
    protected function mediaEntry(MediaRepresentation $media): array
    {
        return $this->withoutEmpty([
            'id' => $media->id(),
            'title' => $media->displayTitle(),
            'media_type' => $media->mediaType(),
            'original_url' => $media->originalUrl(),
            'thumbnail_url' => $media->thumbnailDisplayUrl('medium'),
        ]);
    }

    /**
     * Get the IDs of the sites the caller may see, the default site first.
     */
    protected function getSitePreference(): array
    {
        return $this->sitePreference ??= (function () {
            $siteIds = array_map('intval', array_keys($this->options->getSiteSlugs()));
            sort($siteIds);
            $defaultSiteId = (int) $this->settings->get('default_site');
            if (in_array($defaultSiteId, $siteIds, true)) {
                $siteIds = array_merge([$defaultSiteId], array_values(array_diff($siteIds, [$defaultSiteId])));
            }
            return $siteIds;
        })();
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

    /**
     * Cut long @value strings in decoded JSON-LD.
     *
     * @param int $count Incremented for each text cut
     */
    protected function cutValues($data, int &$count)
    {
        if (is_array($data)) {
            foreach ($data as $key => $item) {
                $data[$key] = $this->cutValues($item, $count);
            }
            return $data;
        }
        if ($data instanceof stdClass) {
            foreach (get_object_vars($data) as $key => $item) {
                if ('@value' === $key && is_string($item)) {
                    $count += mb_strlen($item) > self::TEXT_LIMIT ? 1 : 0;
                    $data->{$key} = $this->cut($item);
                } else {
                    $data->{$key} = $this->cutValues($item, $count);
                }
            }
        }
        return $data;
    }

    /**
     * Cut text longer than the limit, saying how long it was.
     */
    protected function cut(string $text): string
    {
        $length = mb_strlen($text);
        if ($length <= self::TEXT_LIMIT) {
            return $text;
        }
        return sprintf('%s… [truncated, %d characters]', mb_substr($text, 0, self::TEXT_LIMIT), $length);
    }

    /**
     * Shorten text for a summary, at a word boundary.
     */
    public function shorten(string $text, int $limit): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $text = mb_substr($text, 0, $limit);
        $space = mb_strrpos($text, ' ');
        if (false !== $space && $space > $limit / 2) {
            $text = mb_substr($text, 0, $space);
        }
        return rtrim($text, " \t\n,;:.") . '…';
    }

    /**
     * Leave out empty fields, which only cost the model tokens.
     */
    protected function withoutEmpty(array $fields): array
    {
        return array_filter($fields, fn ($field) => null !== $field && '' !== $field && [] !== $field);
    }
}
