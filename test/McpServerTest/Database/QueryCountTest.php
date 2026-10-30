<?php
namespace McpServerTest\Database;

use Doctrine\DBAL\Logging\DebugStack;
use McpServer\Mcp\Context;
use McpServerTest\DbTestCase;

/**
 * Checks that each result on a page of search results costs a bounded number
 * of database queries, so a change that adds a query for every result shows
 * up here rather than as a slow page on a large installation.
 *
 * Omeka's records load some of what they hold one record at a time. A summary
 * needs three of those per result: its values, its sites, for its public
 * link, and its media. A search with "properties" skips the media.
 */
class QueryCountTest extends DbTestCase
{
    /**
     * Twenty items of one class, in one item set, each with a subject and a
     * media, and after the first, a link to the first.
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->adminUser());
        $api = $this->api();
        $set = $api->create('item_sets', [])->getContent()->id();
        $first = null;
        for ($number = 1; $number <= 20; $number++) {
            $data = [
                'o:resource_class' => ['o:id' => self::classId('dctype:Text')],
                'o:item_set' => [['o:id' => $set]],
                'dcterms:title' => [self::literal('dcterms:title', "Item $number")],
                'dcterms:subject' => [self::literal('dcterms:subject', 'Subject')],
                'o:media' => [['o:ingester' => 'html', 'html' => "<p>Media $number</p>"]],
            ];
            if ($first) {
                $data['dcterms:relation'] = [['type' => 'resource:item', 'property_id' => self::propertyId('dcterms:relation'), 'value_resource_id' => $first]];
            }
            $id = $api->create('items', $data)->getContent()->id();
            $first ??= $id;
        }
    }

    /**
     * Count the queries a search for items makes, as a visitor.
     */
    protected function queries(array $arguments): int
    {
        $this->actAs(null);
        $configuration = $this->services()->get('Omeka\Connection')->getConfiguration();
        $log = new DebugStack;
        $configuration->setSQLLogger($log);
        try {
            $result = $this->services()->get('McpServer\ToolRunner')->run('search_items', $arguments + ['sort' => 'title'], new Context(self::PROTOCOL_VERSION));
        } finally {
            $configuration->setSQLLogger(null);
        }
        $this->assertFalse($result->isError(), $this->resultText($result));
        return count($log->queries);
    }

    /**
     * Get the queries each result adds, from a page of 5 and one of 20.
     */
    protected function queriesPerResult(array $arguments): float
    {
        $five = $this->queries(['per_page' => 5] + $arguments);
        $twenty = $this->queries(['per_page' => 20] + $arguments);
        return ($twenty - $five) / 15;
    }

    public function testASummaryCostsAtMostThreeQueriesAResult()
    {
        $this->assertLessThanOrEqual(3, $this->queriesPerResult([]));
    }

    public function testReadingPropertiesCostsAtMostTwoQueriesAResult()
    {
        $this->assertLessThanOrEqual(2, $this->queriesPerResult(['properties' => ['dcterms:title', 'dcterms:relation']]));
    }
}
