<?php
namespace McpServer\Service\Mcp\Format;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Format\SitePageFormatter;

class SitePageFormatterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new SitePageFormatter(
            $services->get('Omeka\BlockLayoutManager'),
            $services->get('ViewRenderer'),
            $services->get('Omeka\Logger')
        );
    }
}
