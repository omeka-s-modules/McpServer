<?php
namespace McpServer;

return [
    'mcp_server' => [
        // MCP tools. The registered name is the tool name clients see. Other
        // modules add tools by adding to this key in their own config.
        'tools' => [
            'factories' => [
                'search_items' => Service\Mcp\Tool\Builtin\SearchItemsFactory::class,
                'get_item' => Service\Mcp\Tool\Builtin\GetItemFactory::class,
                'get_overview' => Service\Mcp\Tool\Builtin\GetOverviewFactory::class,
                'search_media' => Service\Mcp\Tool\Builtin\SearchMediaFactory::class,
                'get_media' => Service\Mcp\Tool\Builtin\GetMediaFactory::class,
                'search_item_sets' => Service\Mcp\Tool\Builtin\SearchItemSetsFactory::class,
                'get_item_set' => Service\Mcp\Tool\Builtin\GetItemSetFactory::class,
                'count_values' => Service\Mcp\Tool\Builtin\CountValuesFactory::class,
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            'McpServer\Controller\Index' => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'invokables' => [
            'McpServer\JsonLdFormatter' => Mcp\Format\JsonLdFormatter::class,
        ],
        'factories' => [
            'McpServer\Dispatcher' => Service\Mcp\Protocol\DispatcherFactory::class,
            'McpServer\ToolManager' => Service\Mcp\Tool\ManagerFactory::class,
            'McpServer\ToolRunner' => Service\Mcp\Tool\ToolRunnerFactory::class,
            'McpServer\NameLookup' => Service\Mcp\Query\NameLookupFactory::class,
            'McpServer\OverviewLists' => Service\Mcp\Query\OverviewListsFactory::class,
            'McpServer\ResourceCounter' => Service\Mcp\Query\ResourceCounterFactory::class,
            'McpServer\ResourceFormatter' => Service\Mcp\Format\ResourceFormatterFactory::class,
            'McpServer\SearchFilters' => Service\Mcp\Tool\Builtin\SearchFiltersFactory::class,
            'McpServer\ValueFormatter' => Service\Mcp\Format\ValueFormatterFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'mcp' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route' => '/mcp',
                    'defaults' => [
                        // Key-auth routes use non-persistent authentication,
                        // so requests start no session and browser cookies
                        // never identify the caller.
                        '__KEYAUTH__' => true,
                        'controller' => 'McpServer\Controller\Index',
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => true,
            ],
        ],
    ],
];
