<?php
namespace McpServer\Mcp\Format;

use Laminas\Log\LoggerInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\DataType\Manager as DataTypeManager;
use Throwable;

/**
 * Formats values for a model: each value's text as the site shows it, what
 * kind of value it is, and its annotations, with long texts and lists cut to
 * OutputLimits.
 *
 * It relies on three things in core, which other modules can vary. A value's
 * JSON gives its text in "@value", or a URI in "@id" with a label in
 * "o:label", but a data type from another module may shape it differently.
 * Each data type renders its values with render(), which some can't do outside
 * a site. And a representation's values() gives each property's values under
 * "values", with the property under "property" and the resource template's
 * label for it under "alternate_label".
 */
class ValueFormatter
{
    protected $dataTypes;

    protected $view;

    protected $logger;

    public function __construct(DataTypeManager $dataTypes, PhpRenderer $view, LoggerInterface $logger)
    {
        $this->dataTypes = $dataTypes;
        $this->view = $view;
        $this->logger = $logger;
    }

    /**
     * Get a resource's values, keyed by term, with the labels the site shows.
     */
    public function values(AbstractResourceEntityRepresentation $resource): array
    {
        $output = [];
        foreach ($resource->values() as $term => $property) {
            if (!$property['values']) {
                continue;
            }
            $output[$term] = [
                'label' => $this->label($property),
                'values' => array_map(fn ($value) => $this->value($value), array_slice($property['values'], 0, OutputLimits::LIST_LIMIT)),
            ];
            if (count($property['values']) > OutputLimits::LIST_LIMIT) {
                $output[$term][OutputLimits::TOTAL] = count($property['values']);
            }
        }
        return $output;
    }

    /**
     * Get one property's values on a resource, a page of
     * OutputLimits::LIST_LIMIT at a time, so a model can read every value of a
     * property the concise form cut.
     *
     * @return array|null ['label', 'total', 'values'], or null when the
     *   resource has no values for the property
     */
    public function fieldPage(AbstractResourceEntityRepresentation $resource, string $term, int $page): ?array
    {
        $property = $resource->values()[$term] ?? null;
        if (!$property || !$property['values']) {
            return null;
        }
        return [
            'label' => $this->label($property),
            OutputLimits::TOTAL => count($property['values']),
            'values' => array_map(
                fn ($value) => $this->value($value),
                array_slice($property['values'], ($page - 1) * OutputLimits::LIST_LIMIT, OutputLimits::LIST_LIMIT)
            ),
        ];
    }

    /**
     * Get part of one value's text, from a character offset,
     * OutputLimits::CHUNK_LIMIT characters at a time, for reading the rest of
     * a text cut at OutputLimits::TEXT_LIMIT.
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
        return ['label' => $this->label($property)]
            + OutputLimits::chunk($this->valueText($value)['text'], $offset);
    }

    /**
     * Get the label the site shows for a property: the resource template's,
     * when it sets one, or the property's own.
     *
     * @param array $property One entry of a representation's values()
     */
    protected function label(array $property): string
    {
        return $property['alternate_label'] ?: $property['property']->label();
    }

    /**
     * Get one value as text, with what kind of value it is, its text cut
     * when it's long, and its annotations.
     */
    protected function value(ValueRepresentation $value): array
    {
        $output = $this->valueText($value);
        if (mb_strlen($output['text']) > OutputLimits::TEXT_LIMIT) {
            $output['text'] = OutputLimits::cut($output['text']);
            $output[OutputLimits::TRUNCATED] = true;
        }
        // Values about the value, such as how certain a date is. Null when
        // there are none, or the caller can't see them.
        $annotation = $value->valueAnnotation();
        if ($annotation) {
            $output['annotations'] = $this->values($annotation);
        }
        return OutputLimits::withoutEmpty($output);
    }

    /**
     * Get one value as text, in full, with what kind of value it is.
     *
     * Doesn't cast the value to a string, which outside a site page gives the
     * wrong text: a linked resource casts to an empty string, since it has no
     * site URL there, and a URI casts to its label alone. A linked resource is
     * read directly, since core's value JSON would also load its thumbnail and
     * build its URL. Other values are read from that JSON.
     */
    public function valueText(ValueRepresentation $value): array
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
                    // differ and it fits whole. A longer one, such as HTML,
                    // is left to the readable text, which can be read in full.
                    $shown = $this->render($value);
                    $output['text'] = $shown ?? $stored;
                    if (null !== $shown && $shown !== trim($stored) && mb_strlen($stored) <= OutputLimits::TEXT_LIMIT) {
                        $output['value'] = $stored;
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
}
