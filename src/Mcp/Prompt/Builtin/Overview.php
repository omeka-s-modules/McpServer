<?php
namespace McpServer\Mcp\Prompt\Builtin;

use McpServer\Mcp\Context;
use McpServer\Mcp\Prompt\PromptInterface;
use McpServer\Mcp\Prompt\PromptResult;

/**
 * Summarizes the installation for someone seeing it for the first time, and
 * ends with topics worth researching, which research then takes.
 */
class Overview implements PromptInterface
{
    public function getTitle(): string
    {
        return 'Get an overview of this installation';
    }

    public function getDescription(): string
    {
        return 'Summarize what\'s here and how it\'s organized, and suggest topics to research next.';
    }

    public function getArguments(): array
    {
        return [];
    }

    public function getMessages(array $arguments, Context $context): PromptResult
    {
        return PromptResult::text(<<<'TEXT'
            Give me an overview of this Omeka installation, for someone seeing it for the first time.

            Start with get_overview. Then tell me, in plain language:
            - what kinds of records are here, and roughly how many of each;
            - how they're organized: the item sets, classes, resource templates, and sites, and what each is for, as far as their names and summaries show;
            - how records are described: which properties are used most, and what the dates and subjects look like, where there are any.

            Keep it to about 300 words. On a large installation, name the largest and most telling parts rather than everything. If a count is refused as too large, use the item sets and classes instead. If there are no items you can see, say so plainly and stop.

            End with three to six topics worth researching next, as short phrases drawn from what's actually here, such as the subjects, places, people, periods, or kinds of record that recur. For each, say in a few words why it stands out. Then ask me to pick one to research.
            TEXT);
    }
}
