<?php
namespace McpServer\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use McpServer\Mcp\Protocol\PromptRequests;
use McpServer\Mcp\Protocol\ToolRequests;
use McpServer\Module;

/**
 * The overview of the MCP server in the admin: its address, how to connect
 * an assistant, and the tools and prompts assistants are offered.
 */
class IndexController extends AbstractActionController
{
    protected $toolRequests;

    protected $promptRequests;

    public function __construct(ToolRequests $toolRequests, PromptRequests $promptRequests)
    {
        $this->toolRequests = $toolRequests;
        $this->promptRequests = $promptRequests;
    }

    public function indexAction()
    {
        $address = $this->url()->fromRoute('mcp', [], ['force_canonical' => true]);
        $view = new ViewModel;
        $view->setVariable('address', $address);
        $view->setVariable('requireSignIn', (bool) $this->settings()->get(Module::REQUIRE_SIGN_IN, false));
        // The tools and prompts as the server describes them to assistants.
        $view->setVariable('tools', $this->toolRequests->listTools());
        $view->setVariable('prompts', $this->promptRequests->listPrompts());
        return $view;
    }
}
