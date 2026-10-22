<?php
namespace McpServer\Service\Stdlib;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Stdlib\ItemFormatter;

class ItemFormatterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ItemFormatter(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\DataTypeManager'),
            $services->get('ViewRenderer'),
            $services->get('Omeka\Logger')
        );
    }
}
