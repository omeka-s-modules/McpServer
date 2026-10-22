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
        // Anyone may reach the endpoint. Every request is anonymous for now,
        // so the API applies a visitor's permissions.
        $this->getServiceLocator()->get('Omeka\Acl')->allow(null, 'McpServer\Controller\Index');
    }
}
