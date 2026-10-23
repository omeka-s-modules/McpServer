<?php
namespace McpServer\Service\Mcp\Protocol;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Protocol\Dispatcher;
use McpServer\Mcp\Protocol\EnvelopeEra;
use McpServer\Mcp\Protocol\HandshakeEra;
use McpServer\Mcp\Protocol\ToolRequests;

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
        $logger = $services->get('Omeka\Logger');
        $instructions = $this->getInstructions($title);
        $tools = new ToolRequests($services->get('McpServer\ToolManager'), $services->get('McpServer\ToolRunner'), $logger);
        return new Dispatcher(
            new HandshakeEra($tools, $serverInfo, $instructions),
            new EnvelopeEra($tools, $serverInfo, $instructions),
            $logger
        );
    }

    /**
     * Get the guidance clients give the model about this server.
     */
    protected function getInstructions(string $title): string
    {
        $installation = '' === $title ? 'an Omeka S installation' : sprintf('the Omeka S installation "%s"', $title);
        return sprintf(
            'This server searches and reads %s: items with their metadata, media, and item sets. '
            . 'Results contain only content that is public on the site.',
            $installation
        );
    }
}
