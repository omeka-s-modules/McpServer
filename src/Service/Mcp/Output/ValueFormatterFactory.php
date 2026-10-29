<?php
namespace McpServer\Service\Mcp\Output;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Output\ValueFormatter;

class ValueFormatterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ValueFormatter(
            $services->get('Omeka\DataTypeManager'),
            $services->get('ViewRenderer'),
            $services->get('Omeka\Logger')
        );
    }
}
