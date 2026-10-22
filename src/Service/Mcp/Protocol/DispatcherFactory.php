<?php
namespace McpServer\Service\Mcp\Protocol;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Protocol\Dispatcher;

class DispatcherFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $title = trim((string) $services->get('Omeka\Settings')->get('installation_title', ''));
        $module = $services->get('Omeka\ModuleManager')->getModule('McpServer');
        $serverInfo = [
            'name' => 'omeka-mcp-server',
            'version' => $module ? (string) $module->getIni('version') : '0',
        ];
        if ('' !== $title) {
            $serverInfo['title'] = $title;
        }
        return new Dispatcher(
            $services->get('McpServer\ToolManager'),
            $services->get('McpServer\ToolRunner'),
            $services->get('Omeka\Logger'),
            $serverInfo,
            $this->getInstructions($title)
        );
    }

    /**
     * Get the guidance clients give the model about this server.
     */
    protected function getInstructions(string $title): string
    {
        $collection = '' === $title ? 'an Omeka S collection' : sprintf('the Omeka S collection "%s"', $title);
        return sprintf(
            'This server searches and reads %s: items with their metadata, media, and item sets. '
            . 'Results contain only content that is public on the site.',
            $collection
        );
    }
}
