<?php
namespace McpServerTest\Database;

use McpServer\Mcp\Tool\Builtin\ResourceCounter;
use McpServer\Service\Mcp\Tool\Builtin\ResourceCounterFactory;
use McpServerTest\DbTestCase;
use OverflowException;

/**
 * Checks what happens above the cap on the records a count covers,
 * ResourceCounter::COUNT_MAX, with a counter whose cap is 2, since a test
 * can't build 200,000 records: the counts refuse, a filter that narrows them
 * below the cap counts, the overview counts its lists another way and says
 * why media types are missing, and the counter itself throws rather than
 * counting nothing.
 */
class CountCapTest extends DbTestCase
{
    protected $ids = [];

    /**
     * Three public items on the same subject, two of them in an item set.
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->setCounterFactory(fn ($services) => new SmallCapCounter($services->get('Omeka\ApiManager'), $services->get('Omeka\EntityManager')));
        $this->actAs($this->adminUser());
        $api = $this->api();
        $this->ids['set'] = $api->create('item_sets', ['dcterms:title' => [self::literal('dcterms:title', 'Pair')]])->getContent()->id();
        foreach (['One', 'Two', 'Three'] as $index => $title) {
            $api->create('items', [
                'o:item_set' => $index < 2 ? [['o:id' => $this->ids['set']]] : [],
                'dcterms:title' => [self::literal('dcterms:title', $title)],
                'dcterms:subject' => [self::literal('dcterms:subject', 'Topic')],
            ]);
        }
    }

    public function tearDown(): void
    {
        $this->setCounterFactory(ResourceCounterFactory::class);
        parent::tearDown();
    }

    protected function setCounterFactory($factory): void
    {
        $services = $this->services();
        $services->setAllowOverride(true);
        $services->setFactory('McpServer\ResourceCounter', $factory);
        $services->setAllowOverride(false);
    }

    public function testCountsAboveTheCapAreRefused()
    {
        foreach ([['count_values', ['property' => 'dcterms:subject']], ['count_properties', []]] as [$name, $arguments]) {
            $result = $this->callTool($name, $arguments);
            $this->assertTrue($result->isError(), $name);
            $this->assertStringContainsString('too many to count at once. Narrow them with filters', $this->resultText($result), $name);
        }
    }

    public function testAFilterBelowTheCapCounts()
    {
        $data = $this->resultData($this->callTool('count_values', ['property' => 'dcterms:subject', 'item_set' => [$this->ids['set']]]));
        $this->assertSame([['value' => 'Topic', 'items' => 2]], $data['values']);
    }

    public function testTheOverviewCountsItsListsAnotherWayAndSaysWhyMediaTypesAreMissing()
    {
        $result = $this->callTool('get_overview');
        $this->assertStringContainsString('Media types aren\'t counted on installations with more than', $this->resultText($result));
        $data = $this->resultData($result);
        $this->assertArrayNotHasKey('media_types', $data);
        $this->assertSame(3, $data['items']);
        $this->assertSame(['Pair' => 2], array_column($data['item_sets'], 'items', 'title'));
    }

    public function testTheCounterThrowsRatherThanCountingNothing()
    {
        $this->actAs(null);
        $this->expectException(OverflowException::class);
        $this->services()->get('McpServer\ResourceCounter')->countValues('items', [], self::propertyId('dcterms:subject'), 10);
    }
}

/**
 * The counter, with a cap a test can reach.
 */
class SmallCapCounter extends ResourceCounter
{
    const COUNT_MAX = 2;
}
