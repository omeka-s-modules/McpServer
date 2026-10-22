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
            return $this->tools->get($name)->call($arguments, $context);
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
