<?php
namespace McpServer\Mcp\Prompt\Builtin;

use McpServer\Mcp\Context;
use McpServer\Mcp\Prompt\PromptInterface;
use McpServer\Mcp\Prompt\PromptResult;

/**
 * Finds what the installation has about a topic, such as one the overview
 * suggested.
 */
class Research implements PromptInterface
{
    public function getTitle(): string
    {
        return 'Research a topic';
    }

    public function getDescription(): string
    {
        return 'Find what this installation has about a subject: where it\'s concentrated, the most relevant records, and what its sites say about it.';
    }

    public function getArguments(): array
    {
        return [
            [
                'name' => 'topic',
                'description' => 'What to research, such as a subject, place, person, or period, in a few words.',
                'required' => true,
            ],
        ];
    }

    public function getMessages(array $arguments, Context $context): PromptResult
    {
        return PromptResult::text(sprintf(<<<'TEXT'
            Research %s in this Omeka installation.

            Search the items, media, and site pages for it, with words and, where they help, filters. Then tell me:
            - how much there is, and where it's concentrated: which item sets, classes, and sites;
            - the most relevant records, with a line on each and its link, where it has one;
            - what the site pages say about it, if any do;
            - related topics worth following, as short phrases.

            Search matches whole words, so try variants and related terms if the first search finds little. If there's nothing here about it, say so, and suggest topics that are here.
            TEXT, $arguments['topic']));
    }
}
