<?php
namespace McpServer\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use McpServer\Mcp\Protocol\ToolRequests;
use McpServer\Module;

/**
 * The overview of the MCP server in the admin: its address, how to connect
 * an assistant, and the tools assistants are offered.
 */
class IndexController extends AbstractActionController
{
    protected $toolRequests;

    public function __construct(ToolRequests $toolRequests)
    {
        $this->toolRequests = $toolRequests;
    }

    public function indexAction()
    {
        $address = $this->url()->fromRoute('mcp', [], ['force_canonical' => true]);
        $view = new ViewModel;
        $view->setVariable('address', $address);
        $view->setVariable('requireSignIn', (bool) $this->settings()->get(Module::REQUIRE_SIGN_IN, false));
        // The tools as tools/list describes them to assistants.
        $view->setVariable('tools', $this->toolRequests->listTools());
        return $view;
    }
}
