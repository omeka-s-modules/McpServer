<?php
namespace McpServer\Service\Mcp\Query;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Query\SearchFilters;

class SearchFiltersFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new SearchFilters($services->get('McpServer\IdResolver'));
    }
}
