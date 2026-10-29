<?php
namespace McpServer\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Controller\McpController;

class McpControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new McpController(
            $services->get('Omeka\Acl'),
            array_values($services->get('Config')['api_adapters']['invokables']),
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\AuthenticationService'),
            $services->get('McpServer\Dispatcher')
        );
    }
}
