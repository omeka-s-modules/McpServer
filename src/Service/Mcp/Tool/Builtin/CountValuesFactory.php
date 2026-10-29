<?php
namespace McpServer\Service\Mcp\Tool\Builtin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\Builtin\CountValues;

class CountValuesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new CountValues(
            $services->get('McpServer\ResourceCounter'),
            $services->get('McpServer\SearchFilters'),
            $services->get('McpServer\IdResolver'),
            $services->get('McpServer\EmptyResultNotes')
        );
    }
}
