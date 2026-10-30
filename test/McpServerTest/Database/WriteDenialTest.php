<?php
namespace McpServerTest\Database;

use McpServer\Mcp\Context;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use McpServerTest\DbTestCase;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Exception\PermissionDeniedException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Entity\Item;
use Omeka\Entity\User;
use Omeka\Test\AbstractHttpControllerTestCase;
use Throwable;

/**
 * Checks that no caller can write at /mcp, even through a tool that tries
 * to, as the rule in ToolInterface promises until write tools exist.
 *
 * A throwaway tool tries each of the API's writes and reports which were
 * refused. Each check first shows the same caller may write outside the
 * endpoint, so the refusals mean something: a Reviewer with an MCP key, and
 * a visitor granted writes on items, as a module may grant them for its own
 * resources.
 *
 * Each request runs in an application of its own, whose access rules the
 * endpoint changes for that request only, so what it reads is committed
 * before the tests and deleted after them.
 */
class WriteDenialTest extends AbstractHttpControllerTestCase
{
    const WRITES = ['create', 'batch_create', 'update', 'batch_update', 'delete', 'batch_delete'];

    protected static $reviewerId;

    protected static $key;

    protected static $targetId;

    // Items made by writes that went through, to delete afterward.
    protected static $created = [];

    public static function setUpBeforeClass(): void
    {
        DbTestCase::installOnce();
        $services = DbTestCase::getApplication()->getServiceManager();
        $em = $services->get('Omeka\EntityManager');
        DbTestCase::switchUser($services, $em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']));
        $reviewer = DbTestCase::makeUser('mcp-writes-reviewer@example.com', 'MCP writes reviewer', 'reviewer');
        self::$key = DbTestCase::makeKey($reviewer, 'MCP writes');
        self::$reviewerId = $reviewer->getId();
        // The item the tool tries to change and delete, the Reviewer's own.
        DbTestCase::switchUser($services, $reviewer);
        self::$targetId = $services->get('Omeka\ApiManager')->create('items', ['o:is_public' => false])->getContent()->id();
    }

    public static function tearDownAfterClass(): void
    {
        $services = DbTestCase::getApplication()->getServiceManager();
        $em = $services->get('Omeka\EntityManager');
        DbTestCase::switchUser($services, $em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']));
        $api = $services->get('Omeka\ApiManager');
        foreach (array_merge([self::$targetId], self::$created) as $id) {
            try {
                $api->delete('items', $id);
            } catch (Throwable $e) {
            }
        }
        // The key goes with its user.
        $api->delete('users', self::$reviewerId);
    }

    /**
     * Start an application for one request, signed in as the Reviewer or no
     * one, and optionally granting visitors writes on items.
     */
    protected function startRequest(bool $asReviewer, bool $grantVisitors = false, string $path = '/')
    {
        $this->reset();
        DbTestCase::setRequestAddress($path);
        $services = $this->getApplication()->getServiceManager();
        if ($asReviewer) {
            $reviewer = $services->get('Omeka\EntityManager')->find(User::class, self::$reviewerId);
            $services->get('Omeka\AuthenticationService')->getStorage()->write($reviewer);
        }
        if ($grantVisitors) {
            $services->get('Omeka\Acl')->allow(null, [ItemAdapter::class, Item::class], self::WRITES);
        }
        return $services;
    }

    /**
     * Assert that the caller may create, update, and delete an item outside
     * the endpoint.
     */
    protected function assertMayWriteOutsideTheEndpoint(bool $asReviewer, bool $grantVisitors): void
    {
        $api = $this->startRequest($asReviewer, $grantVisitors)->get('Omeka\ApiManager');
        $id = $api->create('items', [])->getContent()->id();
        self::$created[] = $id;
        // Kept public, so a visitor can still see it to delete it.
        $api->update('items', $id, ['o:is_public' => true], [], ['isPartial' => true]);
        $api->delete('items', $id);
        $this->addToAssertionCount(1);
    }

    /**
     * Call the throwaway tool through /mcp, and get what happened to each
     * of its attempts: "allowed" or "refused".
     */
    protected function callWriteTool(bool $withKey, bool $grantVisitors = false): array
    {
        $services = $this->startRequest(false, $grantVisitors, '/mcp');
        $tool = new WriteAttemptTool($services->get('Omeka\ApiManager'), self::$targetId);
        $services->get('McpServer\ToolManager')->setFactory('mcp_test_write', fn () => $tool);
        $request = $this->getRequest();
        $request->setContent(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'mcp_test_write', 'arguments' => new \stdClass]]));
        $request->getHeaders()->addHeaders(['Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2025-11-25']);
        $this->dispatch('/mcp' . ($withKey ? '?key=' . self::$key : ''), 'POST');
        $this->assertSame(200, $this->getResponse()->getStatusCode(), $this->getResponse()->getContent());
        $body = json_decode($this->getResponse()->getContent(), true);
        $results = json_decode($body['result']['content'][0]['text'], true);
        self::$created = array_merge(self::$created, $results['created'] ?? []);
        unset($results['created']);
        return $results;
    }

    /**
     * Assert that the target is still there, and still private, reading the
     * database itself, past core's visibility filters and whoever the last
     * request signed in.
     */
    protected function assertTargetUnchanged(): void
    {
        $isPublic = DbTestCase::getApplication()->getServiceManager()->get('Omeka\Connection')
            ->fetchOne('SELECT is_public FROM resource WHERE id = ?', [self::$targetId]);
        $this->assertNotFalse($isPublic, 'The target was deleted');
        $this->assertSame(0, (int) $isPublic, 'The target was changed');
    }

    public function testKeyHolderCantWriteThroughTheEndpoint()
    {
        $this->assertMayWriteOutsideTheEndpoint(true, false);
        $this->assertSame(['read' => 'allowed'] + array_fill_keys(self::WRITES, 'refused'), $this->callWriteTool(true));
        $this->assertTargetUnchanged();
    }

    public function testVisitorGrantedWritesCantWriteThroughTheEndpoint()
    {
        $this->assertMayWriteOutsideTheEndpoint(false, true);
        // A visitor can't read the private target, so its reads and writes
        // of it fail as missing; creating is the write that matters here.
        $results = $this->callWriteTool(false, true);
        $this->assertSame(['create' => 'refused', 'batch_create' => 'refused'], array_intersect_key($results, ['create' => true, 'batch_create' => true]));
        $this->assertNotContains('allowed', array_diff_key($results, ['read' => true]));
        $this->assertTargetUnchanged();
    }
}

/**
 * A tool that tries to read an item and each of the API's writes, and
 * reports which went through.
 */
class WriteAttemptTool implements ToolInterface
{
    protected $api;

    protected $target;

    public function __construct(ApiManager $api, int $target)
    {
        $this->api = $api;
        $this->target = $target;
    }

    public function getTitle(): string
    {
        return 'Test write';
    }

    public function getDescription(): string
    {
        return 'Tries to write.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function getAnnotations(): array
    {
        return [];
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $attempts = [
            'read' => fn () => $this->api->read('items', $this->target),
            'create' => fn () => $this->api->create('items', ['o:is_public' => false]),
            'batch_create' => fn () => $this->api->batchCreate('items', [['o:is_public' => false]]),
            'update' => fn () => $this->api->update('items', $this->target, ['o:is_public' => true], [], ['isPartial' => true]),
            'batch_update' => fn () => $this->api->batchUpdate('items', [$this->target], ['o:is_public' => true]),
            'delete' => fn () => $this->api->delete('items', $this->target),
            'batch_delete' => fn () => $this->api->batchDelete('items', [$this->target]),
        ];
        $results = ['created' => []];
        foreach ($attempts as $name => $attempt) {
            try {
                $content = $attempt()->getContent();
                $results[$name] = 'allowed';
                if (str_ends_with($name, 'create')) {
                    $results['created'] = array_merge($results['created'], array_map(fn ($item) => $item->id(), is_array($content) ? $content : [$content]));
                }
            } catch (PermissionDeniedException $e) {
                $results[$name] = 'refused';
            } catch (Throwable $e) {
                $results[$name] = get_class($e);
            }
        }
        return ToolResult::json($results);
    }
}
