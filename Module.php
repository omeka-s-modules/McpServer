<?php
namespace McpServer;

use Omeka\Module\AbstractModule;
use Laminas\Mvc\MvcEvent;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        // Anyone may reach the endpoint. A request is anonymous unless it
        // carries an API key, and the API applies the caller's permissions.
        $this->getServiceLocator()->get('Omeka\Acl')->allow(null, 'McpServer\Controller\Index');
    }
}
