<?php
namespace McpServer\Form;

use Laminas\Form\Form;

/**
 * The module's configuration: whether the endpoint requires sign-in.
 */
class ConfigForm extends Form
{
    public function init()
    {
        $this->add([
            'type' => 'checkbox',
            'name' => 'require_sign_in',
            'options' => [
                'label' => 'Require sign-in', // @translate
                'info' => 'Only assistants with an MCP key may use the server. Otherwise, any assistant may read public content.', // @translate
            ],
            'attributes' => [
                'id' => 'mcp-server-require-sign-in',
            ],
        ]);
    }
}
