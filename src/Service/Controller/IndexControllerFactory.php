<?php
namespace McpServer\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Controller\IndexController;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IndexController(
            $services->get('Omeka\AuthenticationService'),
            $services->get('Omeka\Acl'),
            array_values($services->get('Config')['api_adapters']['invokables']),
            $services->get('Omeka\EntityManager'),
            fn () => $services->get('McpServer\Dispatcher'),
            $services->get('Omeka\Logger')
        );
    }
}
