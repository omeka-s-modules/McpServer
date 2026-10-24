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
use Omeka\Stdlib\Message;

/**
 * The MCP Server page of the admin: the endpoint's address, and the MCP keys
 * the signed-in user may manage, with a form to create one.
 *
 * Who may manage a user's MCP keys follows core's rule for API keys: the
 * edit-keys privilege on the user, which everyone has for their own account
 * and administrators have for others.
 */
class IndexController extends AbstractActionController
{
    protected $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
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
        $view->setVariable('form', $this->getKeyForm($users));
        return $view;
    }

    public function addKeyAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new Exception\NotFoundException;
        }
        $form = $this->getKeyForm($this->getManagedUsers());
        $form->setData($this->params()->fromPost());
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $this->redirect()->toRoute('admin/mcp-server');
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

        $url = $this->url()->fromRoute('mcp', [], ['force_canonical' => true, 'query' => ['key' => $key]]);
        $message = new Message(
            'MCP key created. Use this address to connect an AI assistant. It can\'t be shown again after you leave this page, so copy it now.<br><br><code>%s</code>', // @translate
            htmlspecialchars($url)
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addWarning($message);
        return $this->redirect()->toRoute('admin/mcp-server');
    }

    public function deleteKeyAction()
    {
        $key = $this->entityManager->find(McpServerKey::class, (int) $this->params()->fromQuery('id'));
        if (!$key) {
            throw new Exception\NotFoundException;
        }
        $this->checkCanEditKeys($key->getOwner());
        $form = $this->getForm(ConfirmForm::class);
        $form->setButtonLabel('Confirm delete'); // @translate
        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $this->entityManager->remove($key);
                $this->entityManager->flush();
                $this->messenger()->addSuccess('MCP key deleted.'); // @translate
                return $this->redirect()->toRoute('admin/mcp-server');
            }
            $this->messenger()->addFormErrors($form);
        }
        $view = new ViewModel;
        $view->setVariable('key', $key);
        $view->setVariable('form', $form);
        return $view;
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
            foreach ($users as $user) {
                $userOptions[$user->getId()] = sprintf('%s (%s)', $user->getName(), $user->getEmail());
            }
        }
        $form = $this->getForm(KeyForm::class, ['users' => $userOptions]);
        if ($userOptions) {
            $form->get('user_id')->setValue($this->identity()->getId());
        }
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
