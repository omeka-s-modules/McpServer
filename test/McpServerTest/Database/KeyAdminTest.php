<?php
namespace McpServerTest\Database;

use McpServer\Entity\McpServerKey;
use McpServer\Module;
use McpServerTest\DbTestCase;
use Omeka\Entity\User;
use Omeka\Mvc\Exception\PermissionDeniedException;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Checks who may manage whose MCP keys on the admin pages, and who may
 * change the module's configuration.
 *
 * The rule follows core's for API keys: everyone manages their own keys,
 * and administrators those of the users they may edit. A request that names
 * another user can't get around it, and a form without its CSRF token
 * changes nothing.
 *
 * Each request runs in an application of its own, so the users and keys it
 * reads are committed before the tests and deleted after them.
 */
class KeyAdminTest extends AbstractHttpControllerTestCase
{
    // User IDs by name: admin, the Global Administrator the installation
    // made; supervisor; researcher; and other, a Reviewer with a key.
    protected static $userIds = [];

    protected static $otherKeyId;

    public static function setUpBeforeClass(): void
    {
        DbTestCase::installOnce();
        $em = self::entityManager();
        self::$userIds['admin'] = $em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com'])->getId();
        foreach (['supervisor' => 'site_admin', 'researcher' => 'researcher', 'other' => 'reviewer'] as $name => $role) {
            self::$userIds[$name] = DbTestCase::makeUser("mcp-keys-$name@example.com", "MCP keys $name", $role)->getId();
        }
        $key = DbTestCase::makeKey($em->find(User::class, self::$userIds['other']), 'Existing key');
        self::$otherKeyId = $em->getRepository(McpServerKey::class)->findOneBy(['hash' => McpServerKey::hashKey($key)])->getId();
    }

    public static function tearDownAfterClass(): void
    {
        $em = self::entityManager();
        $em->clear();
        foreach (['supervisor', 'researcher', 'other'] as $name) {
            // Their keys go with them.
            $em->remove($em->find(User::class, self::$userIds[$name]));
        }
        foreach ($em->getRepository(McpServerKey::class)->findBy(['owner' => self::$userIds['admin']]) as $key) {
            $em->remove($key);
        }
        $em->flush();
        DbTestCase::deleteSetting(Module::REQUIRE_SIGN_IN);
    }

    protected static function entityManager()
    {
        return DbTestCase::getApplication()->getServiceManager()->get('Omeka\EntityManager');
    }

    /**
     * Count the keys, as committed, with a label and, optionally, an owner.
     */
    protected function keyCount(string $label, ?string $owner = null): int
    {
        $em = self::entityManager();
        $em->clear();
        $criteria = ['label' => $label] + ($owner ? ['owner' => self::$userIds[$owner]] : []);
        return count($em->getRepository(McpServerKey::class)->findBy($criteria));
    }

    protected function keyExists(int $id): bool
    {
        $em = self::entityManager();
        $em->clear();
        return null !== $em->find(McpServerKey::class, $id);
    }

    /**
     * Send a request to the admin, signed in as a user, keeping the session
     * between requests, as a browser would.
     *
     * @return array [HTTP status, page]
     */
    protected function send(string $user, string $method, string $path, array $post = []): array
    {
        $this->reset(true);
        DbTestCase::setRequestAddress($path);
        $services = $this->getApplication()->getServiceManager();
        $identity = $services->get('Omeka\EntityManager')->find(User::class, self::$userIds[$user]);
        $services->get('Omeka\AuthenticationService')->getStorage()->write($identity);
        $this->dispatch($path, $method, $post);
        return [$this->getResponse()->getStatusCode(), $this->getResponse()->getContent()];
    }

    /**
     * Get a page's CSRF tokens, as its forms' hidden fields. A module's
     * configuration form has its own, inside core's.
     */
    protected function csrf(string $user, string $path): array
    {
        [, $page] = $this->send($user, 'GET', $path);
        preg_match_all('/<input type="hidden" name="([a-z_]+_csrf)" value="([^"]+)"/i', $page, $matches);
        $this->assertNotEmpty($matches[1], "No CSRF token on $path");
        return array_combine($matches[1], $matches[2]);
    }

    /**
     * Get the users a key's add page offers, by ID, with whether each is
     * chosen, or null when it offers no choice.
     */
    protected function userChoices(string $user): ?array
    {
        [, $page] = $this->send($user, 'GET', '/admin/mcp-server/key/add');
        if (!preg_match('/<select[^>]*name="user_id"[^>]*>(.*?)<\/select>/s', $page, $select)) {
            return null;
        }
        preg_match_all('/<option value="(\d+)"( selected)?/', $select[1], $options, PREG_SET_ORDER);
        return array_map(fn ($option) => !empty($option[2]), array_column($options, null, 1));
    }

    public function testAdministratorManagesOthersKeys()
    {
        [$status, $page] = $this->send('admin', 'GET', '/admin/mcp-server/key');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Existing key', $page);
        $this->assertStringContainsString('MCP keys other', $page);
        $choices = $this->userChoices('admin');
        $this->assertTrue($choices[self::$userIds['admin']], 'The admin is chosen by default');
        $this->assertArrayHasKey(self::$userIds['other'], $choices);
        $token = $this->csrf('admin', '/admin/mcp-server/key/add');
        [$status] = $this->send('admin', 'POST', '/admin/mcp-server/key/add', $token + ['label' => 'For other', 'user_id' => self::$userIds['other']]);
        $this->assertSame(302, $status);
        $this->assertSame(1, $this->keyCount('For other', 'other'));
    }

    public function testBlankLabelIsRefused()
    {
        $token = $this->csrf('admin', '/admin/mcp-server/key/add');
        [$status] = $this->send('admin', 'POST', '/admin/mcp-server/key/add', $token + ['label' => '  ', 'user_id' => self::$userIds['admin']]);
        $this->assertSame(200, $status);
        $this->assertSame(0, $this->keyCount(''));
        $this->assertSame(0, $this->keyCount('  '));
    }

    public function testFormsWithoutTheirCsrfTokenChangeNothing()
    {
        $this->send('admin', 'POST', '/admin/mcp-server/key/add', ['label' => 'No token']);
        $this->assertSame(0, $this->keyCount('No token'));
        $this->send('admin', 'POST', '/admin/mcp-server/key/delete?id=' . self::$otherKeyId, ['submit' => 'Confirm delete']);
        $this->assertTrue($this->keyExists(self::$otherKeyId));
    }

    public function testDeletingNeedsAPostWithItsToken()
    {
        $token = $this->csrf('admin', '/admin/mcp-server/key/add');
        $this->send('admin', 'POST', '/admin/mcp-server/key/add', $token + ['label' => 'To delete', 'user_id' => self::$userIds['admin']]);
        $em = self::entityManager();
        $id = $em->getRepository(McpServerKey::class)->findOneBy(['label' => 'To delete'])->getId();
        [$status] = $this->send('admin', 'GET', "/admin/mcp-server/key/delete?id=$id");
        $this->assertSame(404, $status);
        $this->assertTrue($this->keyExists($id));
        $token = $this->csrf('admin', "/admin/mcp-server/key/delete-confirm?id=$id");
        $this->send('admin', 'POST', "/admin/mcp-server/key/delete?id=$id", $token + ['submit' => 'Confirm delete']);
        $this->assertFalse($this->keyExists($id));
    }

    public function testSupervisorCantMakeAKeyForAnAdministrator()
    {
        $choices = $this->userChoices('supervisor');
        $this->assertArrayHasKey(self::$userIds['other'], $choices);
        $this->assertArrayNotHasKey(self::$userIds['admin'], $choices);
        $token = $this->csrf('supervisor', '/admin/mcp-server/key/add');
        $this->send('supervisor', 'POST', '/admin/mcp-server/key/add', $token + ['label' => 'For the admin', 'user_id' => self::$userIds['admin']]);
        $this->assertSame(0, $this->keyCount('For the admin'));
    }

    public function testResearcherManagesOnlyTheirOwnKeys()
    {
        [, $page] = $this->send('researcher', 'GET', '/admin/mcp-server/key');
        $this->assertStringNotContainsString('Existing key', $page);
        $this->assertNull($this->userChoices('researcher'));
        $token = $this->csrf('researcher', '/admin/mcp-server/key/add');
        $this->send('researcher', 'POST', '/admin/mcp-server/key/add', $token + ['label' => 'Researcher key', 'user_id' => self::$userIds['other']]);
        $this->assertSame([1, 0], [$this->keyCount('Researcher key', 'researcher'), $this->keyCount('Researcher key', 'other')]);
        [$status] = $this->send('researcher', 'GET', '/admin/mcp-server/key/delete-confirm?id=' . self::$otherKeyId);
        $this->assertSame(403, $status);
        // With a valid token, from the confirmation for their own key, so
        // only the permission check can stop it.
        $own = self::entityManager()->getRepository(McpServerKey::class)->findOneBy(['label' => 'Researcher key'])->getId();
        $token = $this->csrf('researcher', "/admin/mcp-server/key/delete-confirm?id=$own");
        [$status] = $this->send('researcher', 'POST', '/admin/mcp-server/key/delete?id=' . self::$otherKeyId, $token + ['submit' => 'Confirm delete']);
        $this->assertSame(403, $status);
        $this->assertTrue($this->keyExists(self::$otherKeyId));
        [$status] = $this->send('researcher', 'GET', '/admin/mcp-server/key/delete-confirm?id=999999999');
        $this->assertSame(404, $status);
    }

    public function testOnlyAGlobalAdministratorConfiguresTheModule()
    {
        // Core refuses the page before its controller runs, which a test
        // receives as the exception rather than a 403 page.
        try {
            [$status] = $this->send('supervisor', 'GET', '/admin/module/configure?id=McpServer');
            $this->assertSame(403, $status);
        } catch (PermissionDeniedException $e) {
        }
        [$status, $page] = $this->send('admin', 'GET', '/admin/module/configure?id=McpServer');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Require sign-in', $page);
        $token = $this->csrf('admin', '/admin/module/configure?id=McpServer');
        $this->send('admin', 'POST', '/admin/module/configure?id=McpServer', $token + ['require_sign_in' => '1']);
        $this->assertTrue((bool) $this->freshSetting(Module::REQUIRE_SIGN_IN));
        DbTestCase::deleteSetting(Module::REQUIRE_SIGN_IN);
    }

    /**
     * Read a setting as committed, past the settings service's cache.
     */
    protected function freshSetting(string $id)
    {
        $value = DbTestCase::getApplication()->getServiceManager()->get('Omeka\Connection')
            ->fetchOne('SELECT value FROM setting WHERE id = ?', [$id]);
        return false === $value ? null : json_decode($value, true);
    }
}
