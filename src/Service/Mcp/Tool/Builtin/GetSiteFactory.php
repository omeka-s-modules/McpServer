<?php
namespace McpServer\Service\Mcp\Tool\Builtin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\Builtin\GetSite;

class GetSiteFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new GetSite(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\SearchFilters'),
            $services->get('Omeka\Site\NavigationLinkManager'),
            $services->get('ViewRenderer')
        );
    }
}
