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
            fn () => $services->get('McpServer\Mcp\Dispatcher'),
            $services->get('Omeka\Logger')
        );
    }
}
