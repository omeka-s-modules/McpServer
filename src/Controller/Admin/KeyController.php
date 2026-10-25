<?php
namespace McpServer\Controller\Admin;

use DateTime;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use McpServer\Entity\McpServerKey;
use McpServer\Form\KeyForm;
use Omeka\Entity\User;
use Omeka\Form\ConfirmForm;
use Omeka\Mvc\Exception;
use Omeka\Permissions\Acl;
use Omeka\Stdlib\Message;

/**
 * The MCP keys the signed-in user may manage, with a page to create one and a
 * sidebar to delete one.
 *
 * Who may manage a user's MCP keys follows core's rule for API keys: the
 * edit-keys privilege on the user, which everyone has for their own account
 * and administrators have for others.
 */
class KeyController extends AbstractActionController
{
    protected $entityManager;

    protected $acl;

    public function __construct(EntityManager $entityManager, Acl $acl)
    {
        $this->entityManager = $entityManager;
        $this->acl = $acl;
    }

    public function indexAction()
    {
        $users = $this->getManagedUsers();
        $keys = $this->entityManager->getRepository(McpServerKey::class)
            ->findBy(['owner' => $users], ['created' => 'ASC']);
        // The signed-in user's own keys first, then others' by owner name.
        $identity = $this->identity();
        usort($keys, fn ($a, $b) => [$a->getOwner() !== $identity, $a->getOwner()->getName()]
            <=> [$b->getOwner() !== $identity, $b->getOwner()->getName()]);
        $view = new ViewModel;
        $view->setVariable('keys', $keys);
        $view->setVariable('showOwners', count($users) > 1);
        return $view;
    }

    public function addAction()
    {
        $form = $this->getKeyForm($this->getManagedUsers());
        $view = new ViewModel;
        $view->setVariable('form', $form);
        if (!$this->getRequest()->isPost()) {
            return $view;
        }
        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $view;
        }
        $data = $form->getData();
        $user = isset($data['user_id'])
            ? $this->entityManager->find(User::class, (int) $data['user_id'])
            : $this->identity();
        $this->checkCanEditKeys($user);
        $key = McpServerKey::generate();
        $entity = new McpServerKey;
        $entity->setOwner($user);
        $entity->setLabel($data['label']);
        $entity->setKey($key);
        $entity->setCreated(new DateTime);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        // The address is shown once, in a read-only box to copy it from.
        $url = $this->url()->fromRoute('mcp', [], ['force_canonical' => true, 'query' => ['key' => $key]]);
        $box = sprintf(
            '<br><input type="text" class="mcp-server-new-address" readonly size="%d" aria-label="%s" value="%s"><br>',
            strlen($url) + 1, htmlspecialchars($this->translate('Address with key')), htmlspecialchars($url)
        );
        $message = $user === $this->identity()
            ? new Message(
                'MCP key created. Copy this address now; it can\'t be shown again.%1$sAnyone with the address can read what you can see. Keep it private, like a password, and delete the key if it\'s exposed.', // @translate
                $box
            )
            : new Message(
                'MCP key created for %1$s. Copy this address now; it can\'t be shown again.%2$sAnyone with the address can read what %1$s can see. Keep it private, like a password, and delete the key if it\'s exposed.', // @translate
                htmlspecialchars($user->getName()), $box
            );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
        return $this->redirect()->toRoute('admin/mcp-server', ['controller' => 'key']);
    }

    /**
     * Get the content of the sidebar that confirms deleting a key.
     */
    public function deleteConfirmAction()
    {
        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setVariable('key', $this->getKeyToDelete());
        $view->setVariable('form', $this->getDeleteForm());
        return $view;
    }

    public function deleteAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new Exception\NotFoundException;
        }
        $key = $this->getKeyToDelete();
        $form = $this->getDeleteForm();
        $form->setData($this->params()->fromPost());
        if ($form->isValid()) {
            $this->entityManager->remove($key);
            $this->entityManager->flush();
            $this->messenger()->addSuccess('MCP key deleted.'); // @translate
        } else {
            $this->messenger()->addFormErrors($form);
        }
        return $this->redirect()->toRoute('admin/mcp-server', ['controller' => 'key']);
    }

    /**
     * Get the users whose MCP keys the signed-in user may manage, by name.
     *
     * @return User[]
     */
    protected function getManagedUsers(): array
    {
        // Most users may manage only their own keys, so the users aren't
        // loaded for them.
        if (!$this->userIsAllowed(User::class, 'edit-keys')) {
            return [$this->identity()];
        }
        $users = $this->entityManager->getRepository(User::class)->findBy([], ['name' => 'ASC']);
        return array_values(array_filter($users, fn ($user) => $this->userIsAllowed($user, 'edit-keys')));
    }

    /**
     * Get the form to create a key, with a choice of user when the signed-in
     * user may manage others' keys.
     *
     * @param User[] $users The users whose keys the signed-in user may manage
     */
    protected function getKeyForm(array $users): KeyForm
    {
        $userOptions = [];
        if (count($users) > 1) {
            // Each with their role, which decides what a key can read.
            $roleLabels = $this->acl->getRoleLabels();
            foreach ($users as $user) {
                $role = $this->translate($roleLabels[$user->getRole()] ?? $user->getRole());
                $userOptions[$user->getId()] = sprintf('%s (%s), %s', $user->getName(), $user->getEmail(), $role);
            }
        }
        $form = $this->getForm(KeyForm::class, ['users' => $userOptions]);
        if ($userOptions) {
            $form->get('user_id')->setValue($this->identity()->getId());
        }
        return $form;
    }

    /**
     * Get the key named in the URL, if the signed-in user may delete it.
     *
     * @throws Exception\NotFoundException|Exception\PermissionDeniedException
     */
    protected function getKeyToDelete(): McpServerKey
    {
        $key = $this->entityManager->find(McpServerKey::class, (int) $this->params()->fromQuery('id'));
        if (!$key) {
            throw new Exception\NotFoundException;
        }
        $this->checkCanEditKeys($key->getOwner());
        return $key;
    }

    protected function getDeleteForm(): ConfirmForm
    {
        $form = $this->getForm(ConfirmForm::class);
        $form->setButtonLabel('Confirm delete'); // @translate
        return $form;
    }

    /**
     * @throws Exception\NotFoundException|Exception\PermissionDeniedException
     */
    protected function checkCanEditKeys(?User $user): void
    {
        if (!$user) {
            throw new Exception\NotFoundException;
        }
        if (!$this->userIsAllowed($user, 'edit-keys')) {
            throw new Exception\PermissionDeniedException;
        }
    }
}
