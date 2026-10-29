<?php
namespace McpServer\Service\Mcp\Output;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Output\ResourceFormatter;

class ResourceFormatterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ResourceFormatter(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\ValueFormatter'),
            $services->get('McpServer\JsonLdFormatter'),
            $services->get('Omeka\Settings')
        );
    }
}
