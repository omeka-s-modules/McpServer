<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Format\OutputLimits;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolException;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ResourceTemplatePropertyRepresentation;

/**
 * Reads one resource template: what a kind of record is meant to hold, as the
 * installation catalogs it.
 *
 * Templates are readable by anyone through the API, as on Omeka's REST API,
 * so this shows any caller the same template. Only the count of the items
 * using it depends on what the caller may see.
 */
class GetResourceTemplate implements ToolInterface
{
    protected $api;

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    public function getTitle(): string
    {
        return 'Get resource template';
    }

    public function getDescription(): string
    {
        return 'Get one resource template by its id, as get_overview lists them: what a kind of record is meant to '
            . 'hold here. Gives its class, the properties records using it should have, in order, each with its '
            . 'label and comment (the template\'s own where it gives them, otherwise the vocabulary\'s), whether '
            . 'it\'s required, and the data types its values take, and how many items use it. Use it to learn what '
            . 'a property means in these records, or to check records against it, such as with count_properties '
            . 'and "template".';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The template\'s id.',
                ],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $id = (new ArgumentReader($arguments))->requireInt('id', 1);
        try {
            $template = $this->api->read('resource_templates', $id)->getContent();
        } catch (NotFoundException $e) {
            throw new ToolException(sprintf('No resource template with id %d exists. get_overview lists the templates items use.', $id));
        }
        $class = $template->resourceClass();
        return ToolResult::json(OutputLimits::withoutEmpty([
            'id' => $template->id(),
            'label' => $template->label(),
            'resource_class' => $class ? ['term' => $class->term(), 'label' => $class->label()] : null,
            'title_property' => $template->titleProperty() ? $template->titleProperty()->term() : null,
            'description_property' => $template->descriptionProperty() ? $template->descriptionProperty()->term() : null,
            'items' => $this->api->search('items', ['resource_template_id' => $id, 'limit' => 0])->getTotalResults(),
            'properties' => array_map(fn ($property) => $this->property($property), $template->resourceTemplateProperties()),
        ]));
    }

    /**
     * Get one of the template's properties: its label and comment, the
     * template's own when it gives them, otherwise the vocabulary's, as
     * Omeka's resource form shows them, and how its values are kept.
     */
    protected function property(ResourceTemplatePropertyRepresentation $templateProperty): array
    {
        $property = $templateProperty->property();
        return OutputLimits::withoutEmpty([
            'term' => $property->term(),
            'label' => $templateProperty->alternateLabel() ?: $property->label(),
            'comment' => $templateProperty->alternateComment() ?: $property->comment(),
            'required' => $templateProperty->isRequired() ?: null,
            // New values start private, an editing default.
            'private_by_default' => $templateProperty->isPrivate() ?: null,
            // No data types means any.
            'data_types' => $templateProperty->dataTypes(),
        ]);
    }
}
