<?php
namespace McpServer\Service\Mcp\Query;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Query\IdResolver;

class IdResolverFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IdResolver(
            $services->get('McpServer\NameLookup'),
            $services->get('McpServer\ResourceCounter')
        );
    }
}
