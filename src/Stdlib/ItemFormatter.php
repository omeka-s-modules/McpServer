<?php
namespace McpServer\Stdlib;

use Laminas\Log\LoggerInterface;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;
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
 * The compact forms follow Anthropic's guidance for agent tools: names rather
 * than internal IDs, values as the site shows them, and nothing empty. The
 * JSON-LD form is Omeka's own, for when a model needs everything.
 */
class ItemFormatter
{
    const DESCRIPTION_LIMIT = 300;

    // Longest text of a single value before it's cut.
    const TEXT_LIMIT = 2000;

    const MEDIA_LIMIT = 50;

    // Most entries of one list, such as a property's values or the links from
    // other items, before it's cut.
    const LIST_LIMIT = 100;

    // Where a summary looks for an item's date, in order.
    const DATE_TERMS = ['dcterms:date', 'dcterms:created', 'dcterms:issued'];

    protected $api;

    protected $settings;

    protected $dataTypes;

    protected $view;

    protected $logger;

    // Slugs of the sites the caller may see, by site ID, fetched once.
    protected $visibleSites;

    public function __construct(ApiManager $api, Settings $settings, DataTypeManager $dataTypes, PhpRenderer $view, LoggerInterface $logger)
    {
        $this->api = $api;
        $this->settings = $settings;
        $this->dataTypes = $dataTypes;
        $this->view = $view;
        $this->logger = $logger;
    }

    /**
     * Get the short form of an item, for search results.
     */
    public function summary(ItemRepresentation $item): array
    {
        $date = null;
        foreach (self::DATE_TERMS as $term) {
            $value = $item->value($term);
            if ($value) {
                $date = $this->value($value)['text'] ?? null;
                break;
            }
        }
        $creators = array_values(array_filter(array_map(
            fn ($value) => $this->value($value)['text'] ?? null,
            $item->value('dcterms:creator', ['all' => true])
        )));
        $class = $item->resourceClass();
        return $this->withoutEmpty([
            'id' => $item->id(),
            'title' => $item->displayTitle(),
            'resource_class' => $class ? ['term' => $class->term(), 'label' => $class->label()] : null,
            'description' => $this->description($item),
            'date' => $date,
            'creator' => $creators,
            'url' => $this->publicUrl($item),
            'api_url' => $item->apiUrl(),
            'thumbnail_url' => $item->thumbnailDisplayUrl('medium'),
        ]);
    }

    /**
     * Get the full compact form of an item.
     *
     * The summary's description, date, and creator are left out, since the
     * values include them in full.
     */
    public function item(ItemRepresentation $item): array
    {
        $class = $item->resourceClass();
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
            'resource_class' => $class ? ['term' => $class->term(), 'label' => $class->label()] : null,
            // "Added" rather than "created", which dcterms:created means.
            'added' => $item->created()->format('c'),
            'modified' => $item->modified() ? $item->modified()->format('c') : null,
            'values' => $values,
            // Media keep the order of the item page.
            'media' => array_map(fn ($media) => $this->media($media), array_slice($media, 0, self::MEDIA_LIMIT)),
            'media_total' => count($media),
            'item_sets' => array_values($itemSets),
            'module_data' => $this->moduleData($item, array_keys($values)),
        ]);
    }

    /**
     * Get the short form of an item set, for search results.
     *
     * @param int $items How many items the caller may see in the set
     */
    public function itemSetSummary(ItemSetRepresentation $itemSet, int $items): array
    {
        return $this->withoutEmpty([
            'id' => $itemSet->id(),
            'title' => $itemSet->displayTitle(),
            'description' => $this->description($itemSet),
            'items' => $items,
            'url' => $this->publicUrl($itemSet),
            'api_url' => $itemSet->apiUrl(),
        ]);
    }

    /**
     * Get the full compact form of an item set.
     *
     * @param int $items How many items the caller may see in the set
     */
    public function itemSet(ItemSetRepresentation $itemSet, int $items): array
    {
        $class = $itemSet->resourceClass();
        $values = $this->values($itemSet);
        return $this->withoutEmpty([
            'id' => $itemSet->id(),
            'title' => $itemSet->displayTitle(),
            'url' => $this->publicUrl($itemSet),
            'api_url' => $itemSet->apiUrl(),
            'resource_class' => $class ? ['term' => $class->term(), 'label' => $class->label()] : null,
            'added' => $itemSet->created()->format('c'),
            'modified' => $itemSet->modified() ? $itemSet->modified()->format('c') : null,
            'items' => $items,
            'values' => $values,
            'module_data' => $this->moduleData($itemSet, array_keys($values)),
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
        return $value ? $this->shorten($this->value($value)['text'] ?? '', self::DESCRIPTION_LIMIT) : null;
    }

    /**
     * Get what other modules add to the item's JSON-LD, such as map locations.
     *
     * Modules extend the JSON-LD through the rep.resource.json event, so
     * reading it brings their data along without any change on their side.
     * What remains after leaving out JSON-LD syntax, core's own "o:" keys, and
     * the item's property values is theirs. It's passed on as is, apart from
     * cut long values and the @context noise.
     *
     * @param array $terms The terms of the item's values, already given
     */
    protected function moduleData(AbstractResourceEntityRepresentation $resource, array $terms): array
    {
        $data = [];
        [$jsonLd] = $this->jsonLd($resource);
        foreach (get_object_vars($jsonLd) as $key => $value) {
            if (str_starts_with($key, '@') || str_starts_with($key, 'o:')
                || 'thumbnail_display_urls' === $key || in_array($key, $terms, true)
            ) {
                continue;
            }
            $data[$key] = $this->withoutContext($value);
        }
        return $data;
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
     * @return array [JSON-LD as a stdClass, full lengths of the cut lists by name]
     */
    public function jsonLd(AbstractResourceEntityRepresentation $resource): array
    {
        // Decode to objects, so empty maps stay {} when encoded again.
        $jsonLd = $this->cutValues(json_decode(json_encode($resource, ToolResult::JSON_FLAGS)));
        $cut = $this->cutLists($jsonLd, '');
        if (isset($jsonLd->{'@reverse'}) && $jsonLd->{'@reverse'} instanceof stdClass) {
            $cut += $this->cutLists($jsonLd->{'@reverse'}, '@reverse ');
        }
        return [$jsonLd, $cut];
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
     * Prefers the default site, if the resource is on it, then its first
     * visible site. Returns null when it's on no site the caller sees.
     *
     * @param ItemRepresentation|ItemSetRepresentation $resource
     */
    public function publicUrl(AbstractResourceEntityRepresentation $resource): ?string
    {
        $visibleSites = $this->getVisibleSites();
        // Not filtered by visibility, unlike the API search of sites.
        $resourceSiteIds = array_keys($resource->sites());
        $siteId = null;
        $defaultSiteId = (int) $this->settings->get('default_site');
        if (isset($visibleSites[$defaultSiteId]) && in_array($defaultSiteId, $resourceSiteIds, true)) {
            $siteId = $defaultSiteId;
        } else {
            foreach ($resourceSiteIds as $resourceSiteId) {
                if (isset($visibleSites[$resourceSiteId])) {
                    $siteId = $resourceSiteId;
                    break;
                }
            }
        }
        return null === $siteId ? null : $resource->siteUrl($visibleSites[$siteId], true);
    }

    /**
     * Get the date a search sorted by, when it sorted by date added or
     * modified, to add to a result's summary.
     */
    public function sortDate(AbstractResourceEntityRepresentation $resource, array $query): array
    {
        switch ($query['sort_by'] ?? null) {
            case 'created':
                return ['added' => $resource->created()->format('c')];
            case 'modified':
                return $resource->modified() ? ['modified' => $resource->modified()->format('c')] : [];
        }
        return [];
    }

    /**
     * Get the short form of a media, for search results.
     */
    public function mediaSummary(MediaRepresentation $media): array
    {
        $item = $media->item();
        return $this->withoutEmpty([
            'id' => $media->id(),
            'title' => $media->displayTitle(),
            'media_type' => $media->mediaType(),
            'item' => ['id' => $item->id(), 'title' => $item->displayTitle()],
            'url' => $this->publicUrl($item),
            'original_url' => $media->originalUrl(),
            'thumbnail_url' => $media->thumbnailDisplayUrl('medium'),
        ]);
    }

    /**
     * Get the full compact form of a media.
     */
    public function mediaDetail(MediaRepresentation $media): array
    {
        $item = $media->item();
        return $this->withoutEmpty([
            'id' => $media->id(),
            'title' => $media->displayTitle(),
            'media_type' => $media->mediaType(),
            'item' => ['id' => $item->id(), 'title' => $item->displayTitle(), 'url' => $this->publicUrl($item)],
            // The original file name or URL.
            'source' => $media->source(),
            'original_url' => $media->originalUrl(),
            'thumbnail_url' => $media->thumbnailDisplayUrl('medium'),
            'values' => $this->values($media),
        ]);
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
     * Get one value as text, with what kind of value it is.
     *
     * Reads core's value JSON rather than casting the value to a string: on
     * this route a linked resource casts to an empty string, since its site
     * URL doesn't exist outside a site, and a URI casts to its label only.
     */
    protected function value(ValueRepresentation $value): array
    {
        $json = $value->jsonSerialize();
        $output = ['text' => null];
        $extra = [];
        if (isset($json['value_resource_id'])) {
            $output['text'] = (string) ($json['display_title'] ?? '');
            $extra['resource'] = ['id' => (int) $json['value_resource_id'], 'type' => $json['value_resource_name'] ?? null];
        } elseif (isset($json['@id']) && is_string($json['@id'])) {
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
                // Other types, such as numeric dates, show a readable form,
                // with the stored form kept beside it when they differ.
                $shown = $this->render($value);
                $output['text'] = $shown ?? $stored;
                if (null !== $shown && $shown !== trim($stored)) {
                    $output['value'] = $this->cut($stored);
                }
            }
        } else {
            $output['text'] = (string) $value;
        }
        $output['type'] = $value->type();
        $output['lang'] = $value->lang();
        if (mb_strlen($output['text']) > self::TEXT_LIMIT) {
            $output['text'] = $this->cut($output['text']);
            $extra['truncated'] = true;
        }
        return $this->withoutEmpty($output + $extra);
    }

    /**
     * Render a value as its data type shows it on the site, as plain text.
     *
     * Returns null when the data type can't render here, for example one
     * that expects a site.
     */
    protected function render(ValueRepresentation $value): ?string
    {
        try {
            try {
                $dataType = $this->dataTypes->get($value->type());
            } catch (ServiceNotFoundException $e) {
                // The module that provided the data type is gone.
                $dataType = $this->dataTypes->get('literal');
            }
            $html = (string) $dataType->render($this->view, $value);
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

    protected function media(MediaRepresentation $media): array
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
     * Get the slugs of the sites the caller may see, by site ID.
     */
    protected function getVisibleSites(): array
    {
        if (null === $this->visibleSites) {
            $this->visibleSites = $this->api->search('sites', [], ['returnScalar' => 'slug'])->getContent();
        }
        return $this->visibleSites;
    }

    /**
     * Cut long @value strings in decoded JSON-LD.
     */
    protected function cutValues($data)
    {
        if (is_array($data)) {
            return array_map(fn ($item) => $this->cutValues($item), $data);
        }
        if ($data instanceof stdClass) {
            foreach (get_object_vars($data) as $key => $item) {
                $data->{$key} = ('@value' === $key && is_string($item)) ? $this->cut($item) : $this->cutValues($item);
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
