<?php
namespace Controller\Admin;

use App\Auth;
use App\Csrf;
use App\Flash;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Entity;
use Slim\Container;
use App\Http\Request;
use App\Http\Response;

class UsersController
{
    /** @var EntityManager */
    protected $em;

    /** @var Flash */
    protected $flash;

    /** @var Auth */
    protected $auth;

    /** @var array */
    protected $form_config;

    /** @var Entity\Repository\UserRepository */
    protected $record_repo;

    /** @var Csrf */
    protected $csrf;

    /** @var string */
    protected $csrf_namespace = 'admin_users';

    /**
     * UsersController constructor.
     * @param EntityManager $em
     * @param Flash $flash
     * @param Auth $auth
     * @param array $form_config
     */
    public function __construct(EntityManager $em, Flash $flash, Auth $auth, Csrf $csrf, array $form_config)
    {
        $this->em = $em;
        $this->flash = $flash;
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->form_config = $form_config;

        $this->record_repo = $this->em->getRepository(Entity\User::class);
    }

    public function indexAction(Request $request, Response $response): Response
    {
        $users = $this->em->createQuery('SELECT u, r FROM Entity\User u LEFT JOIN u.roles r ORDER BY u.name ASC')
            ->execute();

        /** @var \App\Mvc\View $view */
        $view = $request->getAttribute('view');

        return $view->renderToResponse($response, 'admin/users/index', [
            'user' => $request->getAttribute('user'),
            'users' => $users,
            'csrf' => $this->csrf->generate($this->csrf_namespace)
        ]);
    }

    public function editAction(Request $request, Response $response, $id = null): Response
    {
        $form = new \AzuraForms\Form($this->form_config);

        if (!empty($id)) {
            $record = $this->record_repo->find((int)$id);
            $record_defaults = $this->record_repo->toArray($record, true, true);

            unset($record_defaults['auth_password']);

            $form->populate($record_defaults);
        } else {
            $record = null;
        }

        if (!empty($_POST) && $form->isValid($_POST)) {
            $data = $form->getValues();

            if (!($record instanceof Entity\User)) {
                $record = new Entity\User;
            }

            $this->record_repo->fromArray($record, $data);

            try {
                $this->em->persist($record);
                $this->em->flush();

                $this->flash->alert(sprintf(($id) ? _('%s updated.') : _('%s added.'), _('User')), 'green');

                return $response->redirectToRoute('admin:users:index');
            } catch(UniqueConstraintViolationException $e) {
                $this->flash->alert(_('Another user already exists with this e-mail address. Please update the e-mail address.'), 'red');
            }
        }

        /** @var \App\Mvc\View $view */
        $view = $request->getAttribute('view');

        return $view->renderToResponse($response, 'system/form_page', [
            'form' => $form,
            'render_mode' => 'edit',
            'title' => sprintf(($id) ? _('Edit %s') : _('Add %s'), _('User'))
        ]);
    }

    public function deleteAction(Request $request, Response $response, $id, $csrf_token): Response
    {
        $this->csrf->verify($csrf_token, $this->csrf_namespace);

        $user = $this->record_repo->find((int)$id);

        if ($user instanceof Entity\User) {
            $this->em->remove($user);
        }

        $this->em->flush();

        $this->flash->alert('<b>' . sprintf(_('%s deleted.'), _('User')) . '</b>', 'green');

        return $response->redirectToRoute('admin:users:index');
    }

    public function impersonateAction(Request $request, Response $response, $id, $csrf_token): Response
    {
        $this->csrf->verify($csrf_token, $this->csrf_namespace);

        $user = $this->record_repo->find((int)$id);

        if (!($user instanceof Entity\User)) {
            throw new \App\Exception\NotFound(sprintf(_('%s not found.'), _('User')));
        }

        $this->auth->masqueradeAsUser($user);

        $this->flash->alert('<b>' . _('Logged in successfully.') . '</b><br>' . $user->getEmail(), 'green');

        return $response->redirectHome();
    }
}