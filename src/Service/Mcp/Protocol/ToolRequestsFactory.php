<?php
namespace McpServer\Service\Mcp\Protocol;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Protocol\ToolRequests;

class ToolRequestsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ToolRequests(
            $services->get('McpServer\ToolManager'),
            $services->get('McpServer\ToolRunner'),
            $services->get('Omeka\Logger')
        );
    }
}
