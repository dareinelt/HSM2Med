<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\SessionManager;
use App\User\Permission;
use App\User\User;
use App\User\UserException;
use App\User\UserInput;

/**
 * Benutzerverwaltung: Benutzer, Gruppen und die Rechtematrix.
 *
 * Nur wer das Recht "Benutzerverwaltung" hat, erreicht diese Seiten; die Pruefung erfolgt im
 * Kernel anhand der Route. Benutzer werden nie geloescht, nur deaktiviert, damit historische
 * Vorgaenge zuordenbar bleiben. Die Rechte werden je Gruppe vergeben – nicht je Person – damit
 * sich Aenderungen an der Aufbauorganisation an einer Stelle nachziehen lassen.
 */
final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $service = $this->app->userService();

        return Response::html($this->view->render('users/index', [
            'title' => 'Benutzerverwaltung',
            'users' => $service->all(),
            'groups' => $service->groups(),
            'currentUserId' => $this->app->auth()->id(),
            'passwordIsDefault' => $this->app->config->adminPasswordIsDefault(),
            'idleMinutes' => (int) round($this->app->config->authIdleSeconds() / 60),
        ], Permission::USERS));
    }

    public function newForm(Request $request): Response
    {
        return Response::html($this->view->render('users/form', $this->formVars(null, [
            'username' => '',
            'display_name' => '',
            'is_active' => true,
            'groups' => [],
        ])));
    }

    public function create(Request $request): Response
    {
        $service = $this->app->userService();
        $values = $this->rawValues($request);

        try {
            $input = UserInput::fromPost($request->post);
            UserInput::requirePassword($request->post('password'), $input->username);
            $id = $service->create($input, $request->post('password'));
        } catch (UserException $e) {
            return Response::html($this->view->render('users/form', $this->formVars(null, $values, $e->fieldErrors(), $e->getMessage())), 422);
        }

        $this->app->logger()->info('Benutzer angelegt', ['benutzer_id' => $id]);
        SessionManager::flash('success', 'Der Benutzer wurde angelegt.');
        return Response::redirect('/system/users');
    }

    public function editForm(Request $request, array $params = []): Response
    {
        $user = $this->requireUser($params);

        return Response::html($this->view->render('users/form', $this->formVars($user, [
            'username' => $user->username,
            'display_name' => $user->displayName,
            'is_active' => $user->isActive,
            'groups' => array_map(static fn (array $group): int => $group['id'], $user->groups),
        ])));
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params = []): Response
    {
        $user = $this->requireUser($params);
        $values = $this->rawValues($request);
        $currentUserId = $this->app->auth()->id();

        try {
            $this->app->userService()->update($user->id, UserInput::fromPost($request->post), $currentUserId);
        } catch (UserException $e) {
            return Response::html($this->view->render('users/form', $this->formVars($user, $values, $e->fieldErrors(), $e->getMessage())), 422);
        }

        $this->app->logger()->info('Benutzer geändert', ['benutzer_id' => $user->id]);
        SessionManager::flash('success', 'Die Angaben wurden gespeichert.');
        return Response::redirect('/system/users');
    }

    /**
     * Neues Kennwort setzen (Administration); hebt eine Sperre nach Fehlanmeldungen auf.
     *
     * @param array<string, string> $params
     */
    public function setPassword(Request $request, array $params = []): Response
    {
        $user = $this->requireUser($params);

        try {
            $this->app->userService()->setPassword($user->id, $request->post('password'));
        } catch (UserException $e) {
            return Response::html($this->view->render('users/form', $this->formVars($user, [
                'username' => $user->username,
                'display_name' => $user->displayName,
                'is_active' => $user->isActive,
                'groups' => array_map(static fn (array $group): int => $group['id'], $user->groups),
            ], $e->fieldErrors(), $e->getMessage())), 422);
        }

        $this->app->logger()->info('Kennwort zurückgesetzt', ['benutzer_id' => $user->id]);
        SessionManager::flash('success', 'Das Kennwort wurde neu gesetzt.');
        return Response::redirect('/system/users');
    }

    public function groups(Request $request): Response
    {
        return Response::html($this->view->render('users/groups', [
            'title' => 'Gruppen und Rechte',
            'groups' => $this->app->userService()->groups(),
            'catalog' => Permission::CATALOG,
            'errors' => [],
            'message' => null,
            'values' => ['code' => '', 'label' => '', 'description' => ''],
        ], Permission::USERS));
    }

    public function createGroup(Request $request): Response
    {
        try {
            $id = $this->app->userService()->createGroup(
                $request->post('code'),
                $request->post('label'),
                $request->post('description'),
            );
        } catch (UserException $e) {
            return Response::html($this->view->render('users/groups', [
                'title' => 'Gruppen und Rechte',
                'groups' => $this->app->userService()->groups(),
                'catalog' => Permission::CATALOG,
                'errors' => $e->fieldErrors(),
                'message' => $e->getMessage(),
                'values' => [
                    'code' => $request->post('code'),
                    'label' => $request->post('label'),
                    'description' => $request->post('description'),
                ],
            ], Permission::USERS), 422);
        }

        $this->app->logger()->info('Gruppe angelegt', ['gruppe_id' => $id]);
        SessionManager::flash('success', 'Die Gruppe wurde angelegt. Bitte jetzt die Rechte vergeben.');
        return Response::redirect('/system/users/groups/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function groupForm(Request $request, array $params = []): Response
    {
        $group = $this->requireGroup($params);

        return Response::html($this->view->render('users/group', [
            'title' => 'Gruppe: ' . $group['label'],
            'group' => $group,
            'catalog' => Permission::CATALOG,
            'errors' => [],
            'message' => null,
            'values' => [
                'label' => $group['label'],
                'description' => $group['description'],
                'permissions' => $group['permissions'],
            ],
        ], Permission::USERS));
    }

    /**
     * @param array<string, string> $params
     */
    public function saveGroup(Request $request, array $params = []): Response
    {
        $group = $this->requireGroup($params);
        $permissions = Permission::normalize(is_array($request->post['permissions'] ?? null) ? $request->post['permissions'] : []);

        try {
            $this->app->userService()->saveGroup(
                $group['id'],
                $request->post('label'),
                $request->post('description'),
                $permissions,
            );
        } catch (UserException $e) {
            return Response::html($this->view->render('users/group', [
                'title' => 'Gruppe: ' . $group['label'],
                'group' => $group,
                'catalog' => Permission::CATALOG,
                'errors' => $e->fieldErrors(),
                'message' => $e->getMessage(),
                'values' => [
                    'label' => $request->post('label'),
                    'description' => $request->post('description'),
                    'permissions' => $permissions,
                ],
            ], Permission::USERS), 422);
        }

        $this->app->logger()->info('Gruppe geändert', ['gruppe_id' => $group['id'], 'rechte' => (string) count($permissions)]);
        SessionManager::flash('success', sprintf('Die Gruppe "%s" wurde gespeichert. Die Rechte gelten sofort.', $request->post('label')));
        return Response::redirect('/system/users/groups');
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteGroup(Request $request, array $params = []): Response
    {
        $group = $this->requireGroup($params);

        try {
            $this->app->userService()->deleteGroup($group['id']);
        } catch (UserException $e) {
            SessionManager::flash('error', $e->getMessage() . ' ' . implode(' ', $e->fieldErrors()));
            return Response::redirect('/system/users/groups');
        }

        $this->app->logger()->info('Gruppe gelöscht', ['gruppe_id' => $group['id']]);
        SessionManager::flash('success', 'Die Gruppe wurde gelöscht. Betroffene Personen haben danach keine Rechte dieser Gruppe mehr.');
        return Response::redirect('/system/users/groups');
    }

    /**
     * @param array<string, string> $params
     */
    private function requireUser(array $params): User
    {
        $user = $this->app->userService()->user(self::id($params));
        if ($user === null) {
            throw HttpException::notFound('Der Benutzer existiert nicht.');
        }
        return $user;
    }

    /**
     * @param array<string, string> $params
     * @return array{id:int,code:string,label:string,description:string,is_system:bool,member_count:int,permissions:list<string>}
     */
    private function requireGroup(array $params): array
    {
        $group = $this->app->userService()->group(self::id($params));
        if ($group === null) {
            throw HttpException::notFound('Die Gruppe existiert nicht.');
        }
        return $group;
    }

    /**
     * Werte des Formulars; bei Fehlern die abgeschickten Werte, sonst die gespeicherten.
     *
     * @param array{username:string,display_name:string,is_active:bool,groups:list<int>} $values
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function formVars(?User $user, array $values, array $errors = [], ?string $message = null): array
    {
        return [
            'title' => $user === null ? 'Benutzer anlegen' : 'Benutzer: ' . $user->username,
            'user' => $user,
            'values' => $values,
            'groups' => $this->app->userService()->groups(),
            'errors' => $errors,
            'message' => $message,
            'minPasswordLength' => UserInput::MIN_PASSWORD_LENGTH,
            'currentUserId' => $this->app->auth()->id(),
        ];
    }

    /**
     * @return array{username:string,display_name:string,is_active:bool,groups:list<int>}
     */
    private function rawValues(Request $request): array
    {
        return [
            'username' => $request->post('username'),
            'display_name' => $request->post('display_name'),
            'is_active' => in_array($request->post('is_active'), ['1', 'on', 'true'], true),
            'groups' => UserInput::groupIds($request->post['groups'] ?? []),
        ];
    }
}
