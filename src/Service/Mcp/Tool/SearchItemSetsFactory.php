<?php
namespace McpServer\Service\Mcp\Tool;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\SearchItemSets;

class SearchItemSetsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new SearchItemSets(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\ResourceFormatter'),
            $services->get('McpServer\ResourceQueryBuilder')
        );
    }
}
