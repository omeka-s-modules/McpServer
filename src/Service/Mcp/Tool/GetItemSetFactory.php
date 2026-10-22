<?php
namespace McpServer\Service\Mcp\Tool;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\GetItemSet;

class GetItemSetFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new GetItemSet(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\ItemFormatter'),
            $services->get('McpServer\SearchOptions')
        );
    }
}
