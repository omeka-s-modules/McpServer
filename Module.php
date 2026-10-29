<?php
namespace McpServer;

use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use McpServer\Form\ConfigForm;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    // The setting that makes /mcp refuse requests without an MCP key.
    const REQUIRE_SIGN_IN = 'mcp_server_require_sign_in';

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        // Anyone may reach the endpoint. A request is anonymous unless it
        // carries an MCP key, and the API applies the caller's permissions.
        // When the installation requires sign-in, the controller refuses a
        // request without a key.
        $acl->allow(null, 'McpServer\Controller\Mcp');
        // Every signed-in user may open the MCP Server pages, since anyone may
        // have keys; admin pages already require signing in. The key
        // controller checks that the user may manage the keys in question.
        $acl->allow(null, ['McpServer\Controller\Admin\Index', 'McpServer\Controller\Admin\Key']);
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $form = $this->getServiceLocator()->get('FormElementManager')->get(ConfigForm::class);
        $form->setData(['require_sign_in' => $this->getServiceLocator()->get('Omeka\Settings')->get(self::REQUIRE_SIGN_IN, false)]);
        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $this->getServiceLocator()->get('Omeka\Settings')->set(self::REQUIRE_SIGN_IN, (bool) $controller->params()->fromPost('require_sign_in'));
    }

    public function install(ServiceLocatorInterface $services)
    {
        // From application/data/scripts/update-db-data-module.php. Rerun it
        // after changing the entity, and replace these statements.
        $connection = $services->get('Omeka\Connection');
        $connection->exec('CREATE TABLE mcp_server_key (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, `label` VARCHAR(255) NOT NULL, hash VARCHAR(64) NOT NULL, created DATETIME NOT NULL, last_used DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_C17A51B6D1B862B8 (hash), INDEX IDX_C17A51B67E3C61F9 (owner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $connection->exec('ALTER TABLE mcp_server_key ADD CONSTRAINT FK_C17A51B67E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $services->get('Omeka\Connection')->exec('DROP TABLE IF EXISTS mcp_server_key');
        $services->get('Omeka\Settings')->delete(self::REQUIRE_SIGN_IN);
    }
}
