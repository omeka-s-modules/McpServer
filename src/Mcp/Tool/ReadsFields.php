<?php
namespace McpServer\Mcp\Tool;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Stdlib\ResourceFormatter;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * What the tools that read one resource share: reading one field in full, a
 * page of values at a time, or one long text from an offset, and a note
 * saying what a full form cut and how to reach the rest, as the rule in
 * ToolInterface asks.
 *
 * For tools with a ResourceFormatter in $formatter.
 */
trait ReadsFields
{
    /**
     * Get the schema properties for reading one field.
     *
     * @param bool $content Whether "field" also takes "content", for a
     *   media's text
     */
    protected function getFieldSchema(bool $content = false): array
    {
        return [
            'field' => [
                'type' => 'string',
                'description' => sprintf(
                    'Read only this property\'s values, by term, all of them, %d at a time. Use it when a property has more values than the full form shows, or with "offset" to read a text the full form cut.%s',
                    ResourceFormatter::LIST_LIMIT,
                    $content ? ' "content" reads an HTML media\'s text the same way.' : ''
                ),
            ],
            'page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => AbstractSearchTool::PAGE_MAX,
                'default' => 1,
                'description' => 'With "field", the page of values to return, starting at 1.',
            ],
            'value' => [
                'type' => 'integer',
                'minimum' => 1,
                'default' => 1,
                'description' => 'With "field" and "offset", which of the property\'s values to read, by its position, starting at 1.',
            ],
            'offset' => [
                'type' => 'integer',
                'minimum' => 0,
                'default' => 0,
                'description' => sprintf(
                    'With "field", read one value\'s text from this character, starting at 0, %s characters at a time.',
                    number_format(ResourceFormatter::CHUNK_LIMIT)
                ),
            ],
        ];
    }

    /**
     * Read one field's values, or one long text, when the "field" argument
     * asks for it.
     *
     * @param string $label What one resource is called, such as "item set"
     * @return ToolResult|null Null when no field was asked for
     * @throws ToolException When the resource has no values for the field
     */
    protected function readField(AbstractResourceEntityRepresentation $resource, ArgumentReader $reader, string $label): ?ToolResult
    {
        $term = $reader->getText('field');
        if (null === $term) {
            return null;
        }
        if ('content' === $term && $resource instanceof MediaRepresentation) {
            return $this->readContent($resource, $reader);
        }
        $total = count($resource->values()[$term]['values'] ?? []);
        if (!$total) {
            $terms = array_keys(array_filter($resource->values(), fn ($property) => $property['values']));
            throw new ToolException(sprintf('This %s has no values for "%s". Its fields are: %s.', $label, $term, implode(', ', $terms)));
        }
        if ($reader->has('value') || $reader->has('offset')) {
            return $this->readValue($resource, $reader, $term, $total);
        }
        $page = $reader->getInt('page', 1, 1, AbstractSearchTool::PAGE_MAX);
        $field = $this->formatter->field($resource, $term, $page);
        $output = [
            'id' => $resource->id(),
            'title' => $resource->displayTitle(),
            'field' => $term,
            'page' => $page,
            'per_page' => ResourceFormatter::LIST_LIMIT,
        ] + $field;
        $notes = [];
        $pages = (int) ceil($total / ResourceFormatter::LIST_LIMIT);
        if ($page < $pages) {
            $notes[] = sprintf('There are %d values on %d pages. Call again with "page" %d for the next.', $total, $pages, $page + 1);
        }
        [$positions, $inAnnotations] = $this->findCutTexts($field['values'], ($page - 1) * ResourceFormatter::LIST_LIMIT + 1);
        $notes[] = $this->getTextsNote(
            array_map(fn ($position) => [$term, $position], $positions),
            $inAnnotations ? ['annotations'] : []
        );
        if ($this->hasCutAnnotationList($field['values'])) {
            $notes[] = $this->getAnnotationListNote();
        }
        $notes = array_filter($notes);
        return ToolResult::json($output, $notes ? implode(' ', $notes) : null);
    }

    /**
     * Read part of one value's text, from an offset.
     *
     * @param int $total How many values the resource has for the field
     */
    protected function readValue(AbstractResourceEntityRepresentation $resource, ArgumentReader $reader, string $term, int $total): ToolResult
    {
        $this->refusePage($reader);
        $position = $reader->getInt('value', 1, 1);
        if ($position > $total) {
            throw new ToolException(sprintf('%s has %d value%s here, so "value" can be at most %d.', $term, $total, 1 === $total ? '' : 's', $total));
        }
        $offset = $reader->getInt('offset', 0, 0);
        $chunk = $this->formatter->valueChunk($resource, $term, $position, $offset);
        $output = [
            'id' => $resource->id(),
            'title' => $resource->displayTitle(),
            'field' => $term,
            'label' => $chunk['label'],
            'value' => $position,
            'total' => $total,
        ];
        return $this->getChunkResult($output, $chunk, $offset);
    }

    /**
     * Read part of a media's text content, from an offset.
     */
    protected function readContent(MediaRepresentation $media, ArgumentReader $reader): ToolResult
    {
        $this->refusePage($reader);
        // A value of 1, the default, is allowed, since models often send
        // defaults.
        if (1 !== $reader->getInt('value', 1, 1)) {
            throw new ToolException('"content" is a single text, so it takes "offset" but not "value".');
        }
        $offset = $reader->getInt('offset', 0, 0);
        $chunk = $this->formatter->contentChunk($media, $offset);
        if (null === $chunk) {
            throw new ToolException('This media has no text content. Only an HTML media holds its text itself, rather than in a file.');
        }
        $output = [
            'id' => $media->id(),
            'title' => $media->displayTitle(),
            'field' => 'content',
        ];
        return $this->getChunkResult($output, $chunk, $offset);
    }

    /**
     * Refuse a page other than the first, the default, with "offset", since
     * models often send defaults.
     */
    protected function refusePage(ArgumentReader $reader): void
    {
        if (1 !== $reader->getInt('page', 1, 1, AbstractSearchTool::PAGE_MAX)) {
            throw new ToolException('"page" pages through a field\'s values, and "offset" reads one text, so the two don\'t go together.');
        }
    }

    /**
     * Get the result of reading part of a text, with a note giving the next
     * offset when there's more.
     *
     * @param array $chunk ['length', 'text']
     */
    protected function getChunkResult(array $output, array $chunk, int $offset): ToolResult
    {
        $output += ['offset' => $offset, 'length' => $chunk['length'], 'text' => $chunk['text']];
        $end = $offset + mb_strlen($chunk['text']);
        $note = $end < $chunk['length']
            ? sprintf(
                'These are characters %s to %s of %s. Call again with "offset" %d for the next.',
                number_format($offset), number_format($end), number_format($chunk['length']), $end
            )
            : null;
        return ToolResult::json($output, $note);
    }

    /**
     * Say what a full concise form cut, and how to reach the rest.
     *
     * The data marks each cut: a property's total, media_total,
     * module_data_totals, or a truncated flag.
     */
    protected function getCutNote(array $output): ?string
    {
        $notes = [];
        $cutFields = [];
        $readable = [];
        $annotationsCut = false;
        $annotationListsCut = false;
        foreach ($output['values'] ?? [] as $term => $property) {
            if (isset($property['total'])) {
                $cutFields[] = sprintf('%s has %d', $term, $property['total']);
            }
            [$positions, $inAnnotations] = $this->findCutTexts($property['values'], 1);
            foreach ($positions as $position) {
                $readable[] = [$term, $position];
            }
            $annotationsCut = $annotationsCut || $inAnnotations;
            $annotationListsCut = $annotationListsCut || $this->hasCutAnnotationList($property['values']);
        }
        if (!empty($output['content']['truncated'])) {
            $readable[] = ['content', null];
        }
        if ($cutFields) {
            $notes[] = sprintf(
                'Only the first %d values of a property are shown, and %s. Call again with "field" to read all of a property\'s values.',
                ResourceFormatter::LIST_LIMIT, implode(', ', $cutFields)
            );
        }
        if (isset($output['media_total']) && $output['media_total'] > count($output['media'] ?? [])) {
            $notes[] = sprintf(
                'Only the first %d of the %d media are listed. search_media with "item" %d lists them all.',
                count($output['media'] ?? []), $output['media_total'], $output['id']
            );
        }
        if (!empty($output['module_data_totals'])) {
            $notes[] = sprintf(
                'Only the first %d entries of a list in module_data are shown, and %s. The rest can\'t be read through these tools.',
                ResourceFormatter::LIST_LIMIT,
                implode(', ', array_map(fn ($key, $total) => sprintf('%s has %d', $key, $total), array_keys($output['module_data_totals']), $output['module_data_totals']))
            );
        }
        $notes[] = $this->getTextsNote($readable, array_keys(array_filter([
            'annotations' => $annotationsCut,
            'module_data' => !empty($output['module_data_truncated']),
        ])));
        if ($annotationListsCut) {
            $notes[] = $this->getAnnotationListNote();
        }
        $notes = array_filter($notes);
        return $notes ? implode(' ', $notes) : null;
    }

    /**
     * Find which of a property's formatted values had their text cut.
     *
     * @param int $first The position of the first of $values, from 1
     * @return array [$positions, $inAnnotations]: the position of each value
     *   cut, and whether text was cut in their annotations
     */
    protected function findCutTexts(array $values, int $first): array
    {
        $positions = [];
        $inAnnotations = false;
        foreach (array_values($values) as $index => $value) {
            if (!empty($value['truncated'])) {
                $positions[] = $first + $index;
            }
            $inAnnotations = $inAnnotations || $this->hasCutText($value['annotations'] ?? []);
        }
        return [$positions, $inAnnotations];
    }

    /**
     * Whether a list was cut in formatted values' annotations, at any depth.
     */
    protected function hasCutAnnotationList(array $values): bool
    {
        foreach ($values as $value) {
            foreach ($value['annotations'] ?? [] as $property) {
                if (isset($property['total']) || $this->hasCutAnnotationList($property['values'])) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function getAnnotationListNote(): string
    {
        return sprintf(
            'Only the first %d values of a property in an annotation are shown, and the rest can\'t be read through these tools.',
            ResourceFormatter::LIST_LIMIT
        );
    }

    /**
     * Whether any text was cut in formatted values, or in their annotations.
     */
    protected function hasCutText(array $values): bool
    {
        foreach ($values as $property) {
            foreach ($property['values'] as $value) {
                if (!empty($value['truncated']) || $this->hasCutText($value['annotations'] ?? [])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Say which texts were cut, and how to read the rest of each.
     *
     * @param array $readable Each text an offset reads: a term and a value's
     *   position, or "content" and null
     * @param array $unreadable Where else text was cut, such as "annotations"
     */
    protected function getTextsNote(array $readable, array $unreadable): ?string
    {
        $notes = [];
        if ($readable) {
            $names = array_map(fn ($place) => $place[1] ? sprintf('%s value %d', $place[0], $place[1]) : 'the content', $readable);
            $last = array_pop($names);
            $list = $names ? sprintf('%s%s and %s', implode(', ', $names), count($names) > 1 ? ',' : '', $last) : $last;
            [$term, $position] = $readable[0];
            $example = json_encode(['field' => $term] + ($position ? ['value' => $position] : []) + ['offset' => ResourceFormatter::TEXT_LIMIT]);
            $notes[] = sprintf(
                '%s %s cut after %s first %s characters.',
                // The content is named last, so it starts the sentence only
                // when it's alone. A term starting it keeps its case.
                $position ? $list : ucfirst($list),
                $names ? 'were' : 'was',
                $names ? 'their' : 'its',
                number_format(ResourceFormatter::TEXT_LIMIT)
            );
            $notes[] = $names
                ? sprintf(
                    'To read the rest of one, call again with %s "offset" %d, such as %s.',
                    array_filter(array_column($readable, 1)) ? 'its "field", its "value", and' : 'its "field" and',
                    ResourceFormatter::TEXT_LIMIT, $example
                )
                : sprintf('To read the rest, call again with %s.', $example);
            $notes[] = sprintf(
                'Each call returns up to %s characters, and says where the next one starts.',
                number_format(ResourceFormatter::CHUNK_LIMIT)
            );
        }
        if ($unreadable) {
            $notes[] = sprintf(
                'Text in %s was cut after its first %s characters, and the rest can\'t be read through these tools.',
                implode(' and ', $unreadable), number_format(ResourceFormatter::TEXT_LIMIT)
            );
        }
        return $notes ? implode(' ', $notes) : null;
    }
}
