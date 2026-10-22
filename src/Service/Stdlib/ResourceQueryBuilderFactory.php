<?php
namespace McpServer\Service\Stdlib;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Stdlib\ResourceQueryBuilder;

class ResourceQueryBuilderFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ResourceQueryBuilder($services->get('McpServer\SearchOptions'));
    }
}
