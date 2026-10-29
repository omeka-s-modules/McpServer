<?php
namespace McpServer\Mcp\Output;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use stdClass;

/**
 * Gets a resource's JSON-LD, as Omeka's API returns it, and the part of it
 * other modules add, with long texts and lists cut to OutputLimits.
 *
 * It relies on two things in core's JSON-LD. Keys starting with "@" are JSON-
 * LD syntax, and keys starting with "o:" are core's own, as are CORE_KEYS and
 * MEDIA_CORE_KEYS, so any other key that isn't one of the resource's property
 * terms comes from another module. And "@reverse" lists every resource that
 * links to this one, with no limit, so it can be very long.
 */
class JsonLdFormatter
{
    // Core's own key without "o:" on every resource: its thumbnail URLs.
    const CORE_KEYS = ['thumbnail_display_urls'];

    // And on a media: its ingester's data, and what core's own listeners add,
    // an HTML media's markup in the W3C content vocabulary, and a YouTube
    // video's start and end in the W3C time vocabulary.
    const MEDIA_CORE_KEYS = ['data', 'o-cnt:chars', 'o-cnt:characterEncoding', 'o-time:hasBeginning', 'o-time:hasEnd'];

    // The JSON-LD is encoded only to be decoded again, so only the flags that
    // change what comes back: invalid UTF-8 is replaced rather than failing,
    // a float such as 1.0 stays a float, and an error throws.
    const ROUND_TRIP_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Get Omeka's JSON-LD for an item, item set, or media, as the API returns
     * it.
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
        // Decode to objects, so empty maps stay {} when encoded again.
        $jsonLd = json_decode(json_encode($resource, self::ROUND_TRIP_FLAGS));
        // Lists first, so only the texts still shown are cut and counted.
        $cut = $this->cutLists($jsonLd, '');
        if (isset($jsonLd->{'@reverse'}) && $jsonLd->{'@reverse'} instanceof stdClass) {
            $cut += $this->cutLists($jsonLd->{'@reverse'}, '@reverse ');
        }
        $textsCut = 0;
        $jsonLd = $this->cutTexts($jsonLd, $textsCut);
        return [$jsonLd, $cut, $textsCut];
    }

    /**
     * Get what other modules add to a resource's JSON-LD, such as map
     * locations.
     *
     * Modules extend the JSON-LD through the rep.resource.json event, so
     * triggering it brings their data along with no change on their side.
     * It's triggered on an empty JSON-LD rather than the resource's own,
     * which for a record many others link to lists every one of them; a
     * listener that read core's keys from it would find none, though core's
     * and those of the modules known add theirs without reading any. What's
     * left after leaving out JSON-LD syntax, core's own keys, and the
     * resource's property values is theirs. It's passed on as it is, apart
     * from @context keys, which only cost tokens. Long texts and lists are cut
     * as in the JSON-LD form: module_data_totals gives the full length of each
     * list cut, and module_data_truncated says whether a text was cut.
     *
     * @param array $terms The terms of the resource's values, already given
     * @return array The fields: module_data, module_data_totals, and
     *     module_data_truncated
     */
    public function moduleData(AbstractResourceEntityRepresentation $resource, array $terms): array
    {
        $coreKeys = $resource instanceof MediaRepresentation ? [...self::CORE_KEYS, ...self::MEDIA_CORE_KEYS] : self::CORE_KEYS;
        $data = new stdClass;
        $events = $resource->getEventManager();
        $args = $events->prepareArgs(['jsonLd' => []]);
        $events->trigger('rep.resource.json', $resource, $args);
        foreach (get_object_vars(json_decode(json_encode((object) $args['jsonLd'], self::ROUND_TRIP_FLAGS))) as $key => $value) {
            if (str_starts_with($key, '@') || str_starts_with($key, 'o:')
                || in_array($key, $coreKeys, true) || in_array($key, $terms, true)
            ) {
                continue;
            }
            $data->{$key} = $this->withoutContext($value);
        }
        $totals = $this->cutLists($data, '');
        $textsCut = 0;
        $data = $this->cutTexts($data, $textsCut);
        return [
            'module_data' => get_object_vars($data),
            OutputLimits::MODULE_DATA_TOTALS => $totals,
            OutputLimits::MODULE_DATA_TRUNCATED => $textsCut ? true : null,
        ];
    }

    /**
     * Cut an object's long lists, returning their full lengths by name.
     */
    protected function cutLists(stdClass $object, string $prefix): array
    {
        $cut = [];
        foreach (get_object_vars($object) as $key => $value) {
            if (is_array($value) && count($value) > OutputLimits::LIST_LIMIT) {
                $object->{$key} = array_slice($value, 0, OutputLimits::LIST_LIMIT);
                $cut[$prefix . $key] = count($value);
            }
        }
        return $cut;
    }

    /**
     * Cut long strings in decoded JSON-LD: values' @value, and any other text,
     * such as an HTML media's markup or what a module adds.
     *
     * @param int $count Incremented for each text cut
     */
    protected function cutTexts($data, int &$count)
    {
        if (is_string($data)) {
            $count += mb_strlen($data) > OutputLimits::TEXT_LIMIT ? 1 : 0;
            return OutputLimits::cut($data);
        }
        if (is_array($data)) {
            foreach ($data as $key => $item) {
                $data[$key] = $this->cutTexts($item, $count);
            }
            return $data;
        }
        if ($data instanceof stdClass) {
            foreach (get_object_vars($data) as $key => $item) {
                $data->{$key} = $this->cutTexts($item, $count);
            }
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
}
