<?php
namespace McpServer\Service\Stdlib;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Stdlib\ResourceCounter;

class ResourceCounterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ResourceCounter($services->get('Omeka\ApiManager'), $services->get('Omeka\EntityManager'));
    }
}
