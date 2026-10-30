<?php
namespace McpServer\Service\Mcp\Prompt;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Prompt\Manager;

class ManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        return new Manager($services, $config['mcp_server']['prompts']);
    }
}
