<?php
namespace McpServer\Service\Mcp\Prompt\Builtin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Prompt\Builtin\Research;

class ResearchFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Research;
    }
}
