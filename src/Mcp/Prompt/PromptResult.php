<?php
namespace McpServer\Mcp\Prompt;

/**
 * What a prompt returns to the client: the messages that start the
 * conversation.
 *
 * Build one with the named constructors. Other kinds of message, such as one
 * in the assistant's voice or with an image, will arrive as new constructors.
 */
final class PromptResult
{
    private $messages;

    private function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    /**
     * Return one message from the user, as plain text.
     */
    public static function text(string $text): self
    {
        return new self([['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]);
    }

    /**
     * @internal Get the messages as sent to the client.
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
