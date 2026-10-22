<?php
namespace McpServer\Service\Mcp\Tool;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\CountValues;

class CountValuesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new CountValues(
            $services->get('McpServer\ResourceQueryBuilder'),
            $services->get('McpServer\SearchOptions'),
            $services->get('McpServer\ResourceCounter'),
            $services->get('McpServer\ResourceFormatter')
        );
    }
}
