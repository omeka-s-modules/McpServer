<?php
namespace McpServer;

return [
    'mcp_server' => [
        // MCP tools. The registered name is the tool name clients see. Other
        // modules add tools by adding to this key in their own config.
        'tools' => [
            'factories' => [
                'search_items' => Service\Mcp\Tool\SearchItemsFactory::class,
                'get_item' => Service\Mcp\Tool\GetItemFactory::class,
                'get_overview' => Service\Mcp\Tool\GetOverviewFactory::class,
                'search_media' => Service\Mcp\Tool\SearchMediaFactory::class,
                'get_media' => Service\Mcp\Tool\GetMediaFactory::class,
                'search_item_sets' => Service\Mcp\Tool\SearchItemSetsFactory::class,
                'get_item_set' => Service\Mcp\Tool\GetItemSetFactory::class,
                'count_values' => Service\Mcp\Tool\CountValuesFactory::class,
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            'McpServer\Controller\Index' => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            'McpServer\Mcp\Dispatcher' => Service\Mcp\Protocol\DispatcherFactory::class,
            'McpServer\Mcp\ToolManager' => Service\Mcp\Tool\ManagerFactory::class,
            'McpServer\Mcp\ToolRunner' => Service\Mcp\Tool\ToolRunnerFactory::class,
            'McpServer\ItemFormatter' => Service\Stdlib\ItemFormatterFactory::class,
            'McpServer\ResourceQueryBuilder' => Service\Stdlib\ResourceQueryBuilderFactory::class,
            'McpServer\SearchOptions' => Service\Stdlib\SearchOptionsFactory::class,
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
