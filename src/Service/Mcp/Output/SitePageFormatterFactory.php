<?php
namespace McpServer\Service\Mcp\Output;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Output\SitePageFormatter;

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
