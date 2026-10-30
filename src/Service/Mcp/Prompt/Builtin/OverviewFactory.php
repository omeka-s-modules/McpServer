<?php
namespace McpServer\Service\Mcp\Prompt\Builtin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Prompt\Builtin\Overview;

class OverviewFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Overview;
    }
}
