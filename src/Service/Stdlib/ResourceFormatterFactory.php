<?php
namespace McpServer\Service\Stdlib;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Stdlib\ResourceFormatter;

class ResourceFormatterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ResourceFormatter(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Settings'),
            $services->get('McpServer\SearchOptions'),
            $services->get('Omeka\DataTypeManager'),
            $services->get('ViewRenderer'),
            $services->get('Omeka\Logger')
        );
    }
}
