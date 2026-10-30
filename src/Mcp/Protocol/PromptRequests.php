<?php
namespace McpServer\Mcp\Protocol;

use Laminas\Log\LoggerInterface;
use McpServer\Mcp\Context;
use McpServer\Mcp\Prompt\Manager as PromptManager;
use McpServer\Mcp\Prompt\PromptException;
use McpServer\Mcp\Prompt\PromptInterface;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Exception\PermissionDeniedException;
use Throwable;

/**
 * Answers what every era asks about prompts: the capability, the prompt list,
 * and getting a prompt.
 *
 * Nothing here depends on the era.
 */
class PromptRequests
{
    protected $promptManager;

    protected $logger;

    public function __construct(PromptManager $promptManager, LoggerInterface $logger)
    {
        $this->promptManager = $promptManager;
        $this->logger = $logger;
    }

    public function getCapabilities(): array
    {
        return ['prompts' => ['listChanged' => false]];
    }

    /**
     * Describe the registered prompts.
     *
     * @return array The prompts/list entries: each prompt's name, title,
     *   description, and arguments when it has any
     */
    public function listPrompts(): array
    {
        $prompts = [];
        foreach ($this->promptManager->getPromptNames() as $name) {
            try {
                $prompt = $this->promptManager->get($name);
                $definition = ['name' => $name, 'title' => $prompt->getTitle(), 'description' => $prompt->getDescription()];
                $arguments = $prompt->getArguments();
                if ($arguments) {
                    // A list, as MCP requires, even if a prompt keys its
                    // arguments by name.
                    $definition['arguments'] = array_values($arguments);
                }
            } catch (Throwable $e) {
                // One broken prompt shouldn't hide the others.
                $this->logger->err(sprintf('MCP Server: could not describe the prompt "%s": %s', $name, $e));
                continue;
            }
            $prompts[] = $definition;
        }
        return $prompts;
    }

    /**
     * Get a prompt, with its arguments filled in.
     *
     * A prompt that refuses its arguments with a PromptException is answered
     * with its message, as invalid params, and so is one that reads something
     * Omeka's API says doesn't exist or isn't the caller's to see, with the
     * messages tools give, so a prompt needn't catch those. A prompt that fails
     * otherwise, as a broken one from another module might, is left to the
     * dispatcher, which logs it and answers with an internal error.
     *
     * @return array The prompts/get result: ['description', 'messages'], where
     *   messages is the list the prompt's PromptResult gives
     * @throws ProtocolException For an unknown prompt or bad arguments
     */
    public function getPrompt(array $params, Context $context): array
    {
        $prompt = $this->findPrompt($params['name'] ?? null);
        $arguments = $this->checkArguments($params['name'], $prompt, $params['arguments'] ?? []);
        try {
            $result = $prompt->getMessages($arguments, $context);
        } catch (PromptException $e) {
            throw new ProtocolException($e->getMessage(), ProtocolException::INVALID_PARAMS);
        } catch (NotFoundException $e) {
            throw new ProtocolException('The requested resource doesn\'t exist or isn\'t visible to you.', ProtocolException::INVALID_PARAMS);
        } catch (PermissionDeniedException $e) {
            throw new ProtocolException('You don\'t have permission to do that.', ProtocolException::INVALID_PARAMS);
        }
        return ['description' => $prompt->getDescription(), 'messages' => $result->getMessages()];
    }

    /**
     * Get a registered prompt by name.
     *
     * @throws ProtocolException For a name no prompt has
     */
    protected function findPrompt($name): PromptInterface
    {
        if (!is_string($name) || !in_array($name, $this->promptManager->getPromptNames(), true)) {
            throw new ProtocolException(
                sprintf('Unknown prompt: %s', is_string($name) ? $name : '(no name given)'),
                ProtocolException::INVALID_PARAMS
            );
        }
        return $this->promptManager->get($name);
    }

    /**
     * Check the arguments a client sent against those the prompt declares.
     *
     * A blank argument counts as absent, as it does for tools. One the prompt
     * doesn't declare, one that isn't text, or a required one that's absent
     * is refused, as the spec says, so a prompt gets only what it declares,
     * trimmed.
     *
     * @param mixed $given The arguments as the client sent them
     * @return array The arguments, by name
     * @throws ProtocolException
     */
    protected function checkArguments(string $name, PromptInterface $prompt, $given): array
    {
        if (!is_array($given)) {
            throw new ProtocolException('Invalid params: arguments must be an object.', ProtocolException::INVALID_PARAMS);
        }
        $declared = array_column($prompt->getArguments(), null, 'name');

        $unknown = [];
        foreach ($given as $argumentName => $value) {
            if (!isset($declared[$argumentName]) && !$this->isBlank($value)) {
                $unknown[] = $argumentName;
            }
        }
        if ($unknown) {
            throw new ProtocolException(
                sprintf('Unknown argument%s for the prompt %s: %s.', count($unknown) > 1 ? 's' : '', $name, implode(', ', $unknown)),
                ProtocolException::INVALID_PARAMS
            );
        }

        $arguments = [];
        foreach ($declared as $argumentName => $argument) {
            $value = $given[$argumentName] ?? null;
            if ($this->isBlank($value)) {
                if (!empty($argument['required'])) {
                    throw new ProtocolException(sprintf('Missing required argument: %s', $argumentName), ProtocolException::INVALID_PARAMS);
                }
                continue;
            }
            if (!is_string($value)) {
                throw new ProtocolException(sprintf('Invalid argument %s: must be a string.', $argumentName), ProtocolException::INVALID_PARAMS);
            }
            $arguments[$argumentName] = trim($value);
        }
        return $arguments;
    }

    /**
     * Whether an argument counts as absent: missing, an empty list, or blank
     * text, as ToolRunner treats a tool's arguments.
     */
    protected function isBlank($value): bool
    {
        return null === $value || [] === $value || (is_string($value) && '' === trim($value));
    }
}
