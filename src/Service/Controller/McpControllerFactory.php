<?php
namespace McpServer\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Controller\McpController;
use McpServer\Module;

class McpControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new McpController(
            $services->get('Omeka\AuthenticationService'),
            $services->get('Omeka\Acl'),
            array_values($services->get('Config')['api_adapters']['invokables']),
            $services->get('Omeka\EntityManager'),
            fn () => $services->get('McpServer\Dispatcher'),
            $services->get('Omeka\Logger'),
            (bool) $services->get('Omeka\Settings')->get(Module::REQUIRE_SIGN_IN, false)
        );
    }
}
