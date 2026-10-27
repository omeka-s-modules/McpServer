<?php
namespace McpServer\Service\Mcp\Tool\Builtin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use McpServer\Mcp\Tool\Builtin\EmptyResultNotes;

class EmptyResultNotesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new EmptyResultNotes(
            $services->get('McpServer\NameLookup'),
            $services->get('McpServer\ResourceCounter'),
            $services->get('Omeka\AuthenticationService')
        );
    }
}
