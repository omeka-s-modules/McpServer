<?php
namespace McpServer\Service\Mcp\Format;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Format\ResourceFormatter;

class ResourceFormatterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ResourceFormatter(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\JsonLdFormatter'),
            $services->get('McpServer\ValueFormatter')
        );
    }
}
