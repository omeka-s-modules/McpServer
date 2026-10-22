<?php
namespace McpServer\Service\Stdlib;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Stdlib\SearchOptions;

class SearchOptionsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new SearchOptions($services->get('Omeka\ApiManager'), $services->get('McpServer\ResourceCounter'));
    }
}
