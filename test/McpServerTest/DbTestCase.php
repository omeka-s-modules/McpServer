<?php
namespace McpServerTest;

use DateTime;
use Laminas\Config\Reader\Ini;
use Laminas\Uri\Http as HttpUri;
use McpServer\Entity\McpServerKey;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Entity\User;
use Omeka\Mvc\Application;
use Omeka\Test\DbTestCase as OmekaDbTestCase;

/**
 * The base of the tests that need a database.
 *
 * The first of them installs a fresh schema in core's test database, with
 * this module installed and active. Each test then runs in a transaction
 * that's rolled back, as in core's DbTestCase.
 *
 * A tool call made here runs as a request to /mcp would. Each HTTP request
 * makes the module's services anew, and Doctrine starts with nothing loaded
 * and no queries compiled, which matters because core's visibility filters
 * vary by user. So before each call, Doctrine forgets what it loaded and
 * compiled, and the module's services are made for the call.
 */
abstract class DbTestCase extends OmekaDbTestCase
{
    const PROTOCOL_VERSION = '2025-11-25';

    private static $installed = false;

    // IDs by term, which stay the same for the run.
    private static $propertyIds = [];

    private static $classIds = [];

    public static function setUpBeforeClass(): void
    {
        self::installOnce();
    }

    /**
     * Install the test database, the first time any test asks.
     */
    public static function installOnce(): void
    {
        if (!self::$installed) {
            self::install();
            self::$installed = true;
        }
    }

    /**
     * Install Omeka and this module in the test database, then start the
     * application the tests use, which loads the module.
     */
    private static function install(): void
    {
        self::dropSchema();
        self::installSchema();
        $services = self::startApplication();
        $admin = $services->get('Omeka\EntityManager')->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        $services->get('Omeka\AuthenticationService')->getStorage()->write($admin);
        $modules = $services->get('Omeka\ModuleManager');
        $modules->install($modules->getModule('McpServer'));
        $services = self::startApplication();
        $services->get('Router')->setRequestUri(new HttpUri('http://example.com'));
        // The module's services are made anew each time, as for a request.
        $config = $services->get('Config')['service_manager'];
        $services->setAllowOverride(true);
        foreach (array_keys(($config['factories'] ?? []) + ($config['invokables'] ?? [])) as $name) {
            if (str_starts_with($name, 'McpServer\\')) {
                $services->setShared($name, false);
            }
        }
        $services->setAllowOverride(false);
    }

    /**
     * Start the application on the test database, as core's DbTestCase does,
     * but with Omeka's own init(), which also loads the active modules.
     * Core's DbTestCase returns the application this sets.
     */
    private static function startApplication()
    {
        $config = require OMEKA_PATH . '/application/config/application.config.php';
        $config['connection'] = (new Ini)->fromFile(OMEKA_PATH . '/application/test/config/database.ini');
        self::$application = Application::init($config);
        return self::$application->getServiceManager();
    }

    /**
     * Delete a setting from the test database.
     *
     * Directly, since the settings service deletes only what it has cached,
     * and a request in an application of its own may have saved it.
     */
    public static function deleteSetting(string $id): void
    {
        self::getApplication()->getServiceManager()->get('Omeka\Connection')->delete('setting', ['id' => $id]);
    }

    /**
     * Give the next HTTP test request its address before its application
     * starts.
     *
     * Omeka decides how a request signs in when the application starts, from
     * the request's address: on /mcp, by key and for the request only, with
     * no session; elsewhere, through the session. A test's dispatch() sets
     * the address only after the application has started, so it's set here
     * first, as a real request has it.
     */
    public static function setRequestAddress(string $path): void
    {
        $_SERVER['REQUEST_URI'] = $path;
    }

    /**
     * Make a user, as Omeka's admin would.
     */
    public static function makeUser(string $email, string $name, string $role, bool $isActive = true): User
    {
        $em = self::getApplication()->getServiceManager()->get('Omeka\EntityManager');
        $user = new User;
        $user->setEmail($email);
        $user->setName($name);
        $user->setRole($role);
        $user->setIsActive($isActive);
        $em->persist($user);
        $em->flush();
        return $user;
    }

    /**
     * Make an MCP key for a user, and get the key itself, which only its
     * hash is stored for.
     */
    public static function makeKey(User $owner, string $label): string
    {
        $em = self::getApplication()->getServiceManager()->get('Omeka\EntityManager');
        $key = McpServerKey::generate();
        $entity = new McpServerKey;
        $entity->setOwner($em->find(User::class, $owner->getId()));
        $entity->setLabel($label);
        $entity->setKey($key);
        $entity->setCreated(new DateTime);
        $em->persist($entity);
        $em->flush();
        return $key;
    }

    public static function propertyId(string $term): int
    {
        return self::$propertyIds[$term] ??= self::getApplication()->getServiceManager()->get('Omeka\ApiManager')
            ->search('properties', ['term' => $term])->getContent()[0]->id();
    }

    public static function classId(string $term): int
    {
        return self::$classIds[$term] ??= self::getApplication()->getServiceManager()->get('Omeka\ApiManager')
            ->search('resource_classes', ['term' => $term])->getContent()[0]->id();
    }

    /**
     * Get a literal value to create, with any other keys, such as
     * "is_public" or "@language".
     */
    public static function literal(string $term, string $text, array $extra = []): array
    {
        return ['type' => 'literal', 'property_id' => self::propertyId($term), '@value' => $text] + $extra;
    }

    protected function services()
    {
        return self::getApplication()->getServiceManager();
    }

    protected function api()
    {
        return $this->services()->get('Omeka\ApiManager');
    }

    /**
     * Get the Global Administrator the installation made.
     */
    protected function adminUser(): User
    {
        return $this->services()->get('Omeka\EntityManager')->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
    }

    /**
     * Act as a user, or anonymously, as a new request would.
     */
    protected function actAs(?User $user): void
    {
        self::switchUser($this->services(), $user);
    }

    /**
     * Switch to a user, or to no user, as a new request would: Doctrine
     * forgets what it loaded, and the queries it compiled under the last
     * user's visibility filters.
     */
    public static function switchUser($services, ?User $user): void
    {
        $em = $services->get('Omeka\EntityManager');
        $em->flush();
        $em->clear();
        $em->getConfiguration()->getQueryCacheImpl()->deleteAll();
        $auth = $services->get('Omeka\AuthenticationService');
        if ($user) {
            // Fetched again, since the one given is no longer managed.
            $auth->getStorage()->write($em->find(User::class, $user->getId()));
        } else {
            $auth->clearIdentity();
        }
    }

    /**
     * Call a tool as a user, or anonymously, as a request to /mcp would.
     */
    protected function callTool(string $name, array $arguments = [], ?User $caller = null): ToolResult
    {
        $this->actAs($caller);
        return $this->services()->get('McpServer\ToolRunner')->run($name, $arguments, new Context(self::PROTOCOL_VERSION));
    }

    /**
     * Get a result's text: its JSON and its note, joined.
     */
    protected function resultText(ToolResult $result): string
    {
        return implode("\n", array_column(array_filter($result->getContent(), fn ($block) => 'text' === $block['type']), 'text'));
    }

    /**
     * Get a successful result's data, failing the test on a tool error.
     */
    protected function resultData(ToolResult $result): array
    {
        $this->assertFalse($result->isError(), $this->resultText($result));
        return json_decode($result->getContent()[0]['text'], true);
    }
}
