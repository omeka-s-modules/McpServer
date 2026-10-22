<?php
namespace McpServer\Mcp\Tool;

/**
 * What a tool call returns to the client.
 *
 * Build one with the named constructors. Other kinds of result, such as
 * structured output, will arrive as new constructors.
 */
final class ToolResult
{
    const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    private $content;

    private $isError;

    private function __construct(array $content, bool $isError)
    {
        $this->content = $content;
        $this->isError = $isError;
    }

    /**
     * Return one or more blocks of plain text.
     */
    public static function text(string $text, string ...$more): self
    {
        return new self(array_map(fn ($block) => self::textBlock($block), [$text, ...$more]), false);
    }

    /**
     * Return data as JSON in a text block, optionally followed by a short note.
     *
     * @param mixed $data Anything json_encode() accepts
     */
    public static function json($data, ?string $note = null): self
    {
        $content = [self::textBlock(json_encode($data, self::JSON_FLAGS))];
        if (null !== $note) {
            $content[] = self::textBlock($note);
        }
        return new self($content, false);
    }

    /**
     * Add an image after the result's text, for the model to look at.
     *
     * @param string $bytes The image file's contents
     */
    public function withImage(string $bytes, string $mimeType): self
    {
        $image = ['type' => 'image', 'data' => base64_encode($bytes), 'mimeType' => $mimeType];
        return new self([...$this->content, $image], $this->isError);
    }

    /**
     * Return an error the model should see, e.g. that an item wasn't found.
     */
    public static function error(string $message): self
    {
        return new self([self::textBlock($message)], true);
    }

    /**
     * @internal Get the content blocks as sent to the client.
     */
    public function getContent(): array
    {
        return $this->content;
    }

    /**
     * @internal Whether this is an error result.
     */
    public function isError(): bool
    {
        return $this->isError;
    }

    private static function textBlock(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }
}
