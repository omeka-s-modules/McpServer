<?php
namespace McpServer\Service\Mcp\Tool;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\GetMedia;

class GetMediaFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new GetMedia(
            $services->get('Omeka\ApiManager'),
            $services->get('McpServer\ResourceFormatter'),
            $services->get('Omeka\File\Store'),
            $services->get('Omeka\File\ThumbnailManager')
        );
    }
}
