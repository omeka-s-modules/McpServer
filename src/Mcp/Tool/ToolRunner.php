<?php
namespace McpServer\Mcp\Tool;

use Laminas\Log\LoggerInterface;
use McpServer\Mcp\Exception\ToolException;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Exception\PermissionDeniedException;
use Omeka\Api\Exception\ValidationException;
use Throwable;

/**
 * Runs registered tools, independent of how the protocol is spoken.
 *
 * Failures inside a tool become error results the model can read, as the MCP
 * spec asks, rather than protocol errors.
 */
class ToolRunner
{
    protected $tools;

    protected $logger;

    public function __construct(Manager $tools, LoggerInterface $logger)
    {
        $this->tools = $tools;
        $this->logger = $logger;
    }

    /**
     * Whether a tool may be called by this name.
     */
    public function canRun(string $name): bool
    {
        return in_array($name, $this->tools->getToolNames(), true);
    }

    /**
     * Run a tool. Check canRun() first.
     */
    public function run(string $name, array $arguments, Context $context): ToolResult
    {
        try {
            $tool = $this->tools->get($name);
            $result = $tool->call($arguments, $context);
            $this->refuseUnknownArguments($name, $tool->getInputSchema(), $arguments);
            return $result;
        } catch (ToolException $e) {
            return ToolResult::error($e->getMessage());
        } catch (NotFoundException $e) {
            return ToolResult::error('The requested resource doesn\'t exist or isn\'t visible to you.');
        } catch (PermissionDeniedException $e) {
            return ToolResult::error('You don\'t have permission to do that.');
        } catch (ValidationException $e) {
            return ToolResult::error($this->getValidationMessage($e));
        } catch (Throwable $e) {
            $this->logger->err(sprintf('MCP Server: the tool "%s" failed: %s', $name, $e));
            return ToolResult::error('The tool failed. The error was logged on the server.');
        }
    }

    /**
     * Refuse arguments a tool's schema doesn't name, when the schema allows
     * no others, so a misspelled filter can't pass for one applied.
     *
     * Checked after the tool runs, so a tool's own refusal of an argument it
     * knows but can't apply, with its advice, comes first. A blank argument
     * counts as absent, as tools treat blank arguments.
     *
     * @throws ToolException
     */
    protected function refuseUnknownArguments(string $name, array $schema, array $arguments): void
    {
        if (false !== ($schema['additionalProperties'] ?? true)) {
            return;
        }
        $known = array_keys($schema['properties'] ?? []);
        $given = array_filter($arguments, fn ($value) => null !== $value && [] !== $value && !(is_string($value) && '' === trim($value)));
        $unknown = array_diff(array_map('strval', array_keys($given)), $known);
        if ($unknown) {
            throw new ToolException(sprintf(
                'Unknown argument%s %s. %s takes: %s.',
                count($unknown) > 1 ? 's' : '',
                implode(', ', array_map(fn ($key) => sprintf('"%s"', $key), $unknown)),
                $name, implode(', ', $known)
            ));
        }
    }

    protected function getValidationMessage(ValidationException $e): string
    {
        $messages = [];
        $errors = $e->getErrorStore()->getErrors();
        array_walk_recursive($errors, function ($message) use (&$messages) {
            $messages[] = (string) $message;
        });
        return $messages
            ? sprintf('The request was rejected: %s', implode(' ', $messages))
            : 'The request was rejected as invalid.';
    }
}
