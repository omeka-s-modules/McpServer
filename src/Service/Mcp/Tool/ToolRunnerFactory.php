<?php
namespace McpServer\Service\Mcp\Tool;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\ToolRunner;

class ToolRunnerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ToolRunner($services->get('McpServer\Mcp\ToolManager'), $services->get('Omeka\Logger'));
    }
}
