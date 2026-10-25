<?php
namespace McpServer\Service\Controller\Admin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Controller\Admin\KeyController;

class KeyControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new KeyController(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\Acl')
        );
    }
}
