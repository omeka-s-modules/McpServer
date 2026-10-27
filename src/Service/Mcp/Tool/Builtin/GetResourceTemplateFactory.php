<?php
namespace McpServer\Service\Mcp\Tool\Builtin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\Builtin\GetResourceTemplate;

class GetResourceTemplateFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new GetResourceTemplate($services->get('Omeka\ApiManager'));
    }
}
