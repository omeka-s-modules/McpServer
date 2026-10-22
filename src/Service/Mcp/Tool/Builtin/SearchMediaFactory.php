<?php
namespace McpServer\Service\Mcp\Tool\Builtin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\Builtin\SearchMedia;

class SearchMediaFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new SearchMedia(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\ResourceFormatter'),
            $services->get('McpServer\SearchFilters')
        );
    }
}
