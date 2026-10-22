<?php
namespace McpServer\Service\Mcp\Query;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Query\OverviewLists;

class OverviewListsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new OverviewLists(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\ResourceCounter'),
            $services->get('McpServer\NameLookup')
        );
    }
}
