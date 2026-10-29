<?php
namespace McpServer;

return [
    'mcp_server' => [
        // MCP tools. The registered name is the tool name clients see. Other
        // modules add tools by adding to this key in their own config.
        // Listed in the order tools/list gives them: the overview first, which
        // several descriptions tell the model to call first, then the counts,
        // which span the kinds of record, then each kind's search and read,
        // and a site before its pages.
        'tools' => [
            'factories' => [
                'get_overview' => Service\Mcp\Tool\Builtin\GetOverviewFactory::class,
                'count_values' => Service\Mcp\Tool\Builtin\CountValuesFactory::class,
                'count_properties' => Service\Mcp\Tool\Builtin\CountPropertiesFactory::class,
                'search_items' => Service\Mcp\Tool\Builtin\SearchItemsFactory::class,
                'get_item' => Service\Mcp\Tool\Builtin\GetItemFactory::class,
                'search_media' => Service\Mcp\Tool\Builtin\SearchMediaFactory::class,
                'get_media' => Service\Mcp\Tool\Builtin\GetMediaFactory::class,
                'search_item_sets' => Service\Mcp\Tool\Builtin\SearchItemSetsFactory::class,
                'get_item_set' => Service\Mcp\Tool\Builtin\GetItemSetFactory::class,
                'get_resource_template' => Service\Mcp\Tool\Builtin\GetResourceTemplateFactory::class,
                'get_site' => Service\Mcp\Tool\Builtin\GetSiteFactory::class,
                'search_site_pages' => Service\Mcp\Tool\Builtin\SearchSitePagesFactory::class,
                'get_site_page' => Service\Mcp\Tool\Builtin\GetSitePageFactory::class,
            ],
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'controllers' => [
        'factories' => [
            'McpServer\Controller\Mcp' => Service\Controller\McpControllerFactory::class,
            'McpServer\Controller\Admin\Index' => Service\Controller\Admin\IndexControllerFactory::class,
            'McpServer\Controller\Admin\Key' => Service\Controller\Admin\KeyControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'service_manager' => [
        'invokables' => [
            'McpServer\JsonLdFormatter' => Mcp\Output\JsonLdFormatter::class,
        ],
        'factories' => [
            'McpServer\Dispatcher' => Service\Mcp\Protocol\DispatcherFactory::class,
            'McpServer\ToolRequests' => Service\Mcp\Protocol\ToolRequestsFactory::class,
            'McpServer\ToolManager' => Service\Mcp\Tool\ManagerFactory::class,
            'McpServer\ToolRunner' => Service\Mcp\Tool\ToolRunnerFactory::class,
            'McpServer\EmptyResultNotes' => Service\Mcp\Output\EmptyResultNotesFactory::class,
            'McpServer\IdResolver' => Service\Mcp\Query\IdResolverFactory::class,
            'McpServer\NameLookup' => Service\Mcp\Query\NameLookupFactory::class,
            'McpServer\ResourceCounter' => Service\Mcp\Query\ResourceCounterFactory::class,
            'McpServer\ResourceFormatter' => Service\Mcp\Output\ResourceFormatterFactory::class,
            'McpServer\SearchFilters' => Service\Mcp\Query\SearchFiltersFactory::class,
            'McpServer\SitePageFormatter' => Service\Mcp\Output\SitePageFormatterFactory::class,
            'McpServer\ValueFormatter' => Service\Mcp\Output\ValueFormatterFactory::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'js_translate_strings' => [
        'Show more', // @translate
        'Show less', // @translate
        'Copy address', // @translate
        'Copied', // @translate
        'Selected. Copy it with your keyboard.', // @translate
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'MCP Server', // @translate
                'route' => 'admin/mcp-server',
                'resource' => 'McpServer\Controller\Admin\Index',
                'pages' => [
                    [
                        'label' => 'Overview', // @translate
                        'route' => 'admin/mcp-server',
                        'controller' => 'index',
                        'resource' => 'McpServer\Controller\Admin\Index',
                    ],
                    [
                        'label' => 'Keys', // @translate
                        'route' => 'admin/mcp-server',
                        'controller' => 'key',
                        'resource' => 'McpServer\Controller\Admin\Key',
                    ],
                ],
            ],
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
                        'controller' => 'McpServer\Controller\Mcp',
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => true,
            ],
            // The MCP Server pages of the admin: an overview of the server,
            // and the MCP keys users manage, each with its own controller.
            'admin' => [
                'child_routes' => [
                    'mcp-server' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/mcp-server[/:controller[/:action]]',
                            'constraints' => [
                                'controller' => 'index|key',
                                'action' => '[a-zA-Z][a-zA-Z0-9-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'McpServer\Controller\Admin',
                                'controller' => 'index',
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
