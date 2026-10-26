<?php
namespace McpServer\Service\Mcp\Tool\Builtin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\Builtin\CountFields;

class CountFieldsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new CountFields(
            $services->get('McpServer\SearchFilters'),
            $services->get('McpServer\ResourceCounter'),
            $services->get('McpServer\NameLookup')
        );
    }
}
