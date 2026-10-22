<?php
namespace McpServer\Service\Mcp\Tool;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\SearchItems;

class SearchItemsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new SearchItems(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\ItemFormatter'),
            $services->get('McpServer\ResourceQueryBuilder')
        );
    }
}
