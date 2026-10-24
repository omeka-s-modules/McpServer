<?php
namespace McpServer\Form;

use Laminas\Form\Form;
use Laminas\InputFilter\InputFilterProviderInterface;

/**
 * Create an MCP key.
 *
 * Option "users": the users to choose from, as [id => label], when the
 * signed-in user may manage others' keys. Without it, the key is the signed-in
 * user's own.
 */
class KeyForm extends Form implements InputFilterProviderInterface
{
    public function init()
    {
        $this->add([
            'type' => 'text',
            'name' => 'label',
            'options' => [
                'label' => 'Label', // @translate
            ],
            'attributes' => [
                'id' => 'mcp-server-key-label',
                'required' => true,
                'maxlength' => 255,
            ],
        ]);
        if ($this->getOption('users')) {
            $this->add([
                'type' => 'select',
                'name' => 'user_id',
                'options' => [
                    'label' => 'User', // @translate
                    'value_options' => $this->getOption('users'),
                ],
                'attributes' => [
                    'id' => 'mcp-server-key-user',
                    'required' => true,
                ],
            ]);
        }
        $this->add([
            'type' => 'submit',
            'name' => 'submit',
            'attributes' => [
                'value' => 'Create key', // @translate
            ],
        ]);
    }

    public function getInputFilterSpecification()
    {
        return [
            'label' => [
                'required' => true,
                'filters' => [
                    ['name' => 'StringTrim'],
                ],
                'validators' => [
                    [
                        'name' => 'StringLength',
                        'options' => ['max' => 255],
                    ],
                ],
            ],
        ];
    }
}
