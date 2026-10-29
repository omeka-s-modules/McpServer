<?php
namespace McpServer\Service\Mcp\Query;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Query\ResourceCounter;

class ResourceCounterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ResourceCounter($services->get('Omeka\ApiManager'), $services->get('Omeka\EntityManager'));
    }
}
