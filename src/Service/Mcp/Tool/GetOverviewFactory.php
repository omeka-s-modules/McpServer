<?php
namespace McpServer\Service\Mcp\Tool;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\GetOverview;

class GetOverviewFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new GetOverview(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\SearchOptions'),
            $services->get('McpServer\ResourceCounter'),
            $services->get('McpServer\ResourceFormatter')
        );
    }
}
