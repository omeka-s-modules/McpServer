<?php
namespace McpServer\Service\Mcp\Protocol;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Protocol\PromptRequests;

class PromptRequestsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new PromptRequests(
            $services->get('McpServer\PromptManager'),
            $services->get('Omeka\Logger')
        );
    }
}
