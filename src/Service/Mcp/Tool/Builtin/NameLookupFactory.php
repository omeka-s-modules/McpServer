<?php
namespace McpServer\Service\Mcp\Tool\Builtin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\Builtin\NameLookup;

class NameLookupFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new NameLookup(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\ResourceCounter')
        );
    }
}
