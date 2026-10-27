<?php
namespace McpServer\Service\Mcp\Tool\Builtin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\Builtin\SearchSitePages;

class SearchSitePagesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new SearchSitePages(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\SitePageFormatter'),
            $services->get('McpServer\SearchFilters')
        );
    }
}
