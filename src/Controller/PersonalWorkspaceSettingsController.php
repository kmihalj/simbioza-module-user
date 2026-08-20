<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Controller;

use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspacePresentationRegistry;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use AaiEduHr\SimbiozaModuleUser\Service\SimbiozaUserModuleViewRenderer;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function is_array;
use function is_numeric;
use function is_scalar;
use function trim;

/** HR: Administratorski HTTP adapter za osobna područja. EN: Administrator HTTP adapter for personal Workspaces. */
final readonly class PersonalWorkspaceSettingsController
{
    /** HR: Prima samo javne poslovne i framework servise. EN: Receives only public business and framework services. */
    public function __construct(
        private ResponseFactory $responses,
        private SimbiozaUserModuleViewRenderer $views,
        private PersonalWorkspaceService $personalWorkspaces,
        private AuthnHandlerInterface $authn,
        private UrlGenerator $urls,
        private AlertHandler $alerts,
        private WorkspacePresentationRegistry $presentations,
    ) {
    }

    /** HR: Prikazuje globalno pravilo, iznimke i stanje svih aktivnih korisnika. EN: Displays the global rule, exceptions, and all active-user statuses. */
    public function index(): ResponseInterface
    {
        $users = $this->personalWorkspaces->tablesReady()
            ? $this->personalWorkspaces->administrationRows()
            : [];
        foreach ($users as $index => $user) {
            $workspace = is_array($user['personal_workspace'] ?? null)
                ? WorkspaceValue::stringKeyArray($user['personal_workspace'])
                : null;
            if (is_array($workspace)) {
                $workspace = $this->presentations->one($workspace);
                $users[$index]['personal_workspace'] = $workspace;
            }

            $slug = is_array($workspace) && is_scalar($workspace['slug'] ?? null)
                ? trim((string)$workspace['slug'])
                : '';
            $users[$index]['personal_workspace_path'] = $slug !== ''
                ? $this->workspacePath($slug)
                : null;
        }

        return $this->views->render('settings/personal_workspaces', [
            'title' => __('Osobna područja'),
            'tablesReady' => $this->personalWorkspaces->tablesReady(),
            'automaticCreationEnabled' => $this->personalWorkspaces->automaticCreationEnabled(),
            'users' => $users,
            'settingsMenuActiveSection' => 'simbioza-user.personal-workspaces.settings',
            'savePath' => $this->path(
                'simbioza-user.personal-workspaces.settings.save',
                '/settings/personal-workspaces',
            ),
            'provisionPath' => $this->path(
                'simbioza-user.personal-workspaces.provision',
                '/settings/personal-workspaces/provision',
            ),
            'userPolicyPath' => $this->path(
                'simbioza-user.personal-workspaces.user-policy',
                '/settings/personal-workspaces/user-policy',
            ),
            'createPath' => $this->path(
                'simbioza-user.personal-workspaces.create',
                '/settings/personal-workspaces/create',
            ),
        ]);
    }

    /** HR: Sprema globalni prekidač automatske izrade. EN: Saves the global automatic-creation switch. */
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->body($request);
            $this->personalWorkspaces->setAutomaticCreationEnabled(
                $this->checked($body['enabled'] ?? null),
                $this->actorUserId(),
            );
            $this->success(__('Postavke osobnih područja su spremljene.'));
        } catch (Throwable $throwable) {
            $this->danger($throwable->getMessage());
        }

        return $this->redirect();
    }

    /** HR: Izrađuje nedostajuća područja svim dopuštenim aktivnim korisnicima. EN: Creates missing spaces for all eligible active users. */
    public function provision(): ResponseInterface
    {
        try {
            $result = $this->personalWorkspaces->provisionExistingUsers($this->actorUserId());
            $this->success(sprintf(
                __('Izrađeno: %1$d; već postoji: %2$d; isključeno: %3$d; neuspjelo: %4$d.'),
                $result['created'],
                $result['existing'],
                $result['disabled'],
                $result['failed'],
            ));
        } catch (Throwable $throwable) {
            $this->danger($throwable->getMessage());
        }

        return $this->redirect();
    }

    /** HR: Sprema iznimku automatske izrade jednog korisnika. EN: Saves one user's automatic-creation exception. */
    public function saveUserPolicy(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->body($request);
            $userId = $this->positiveInt($body['user_id'] ?? null);
            $this->personalWorkspaces->setAutomaticCreationForUser(
                $userId,
                $this->checked($body['enabled'] ?? null),
                $this->actorUserId(),
            );
            $this->success(__('Postavka korisnika je spremljena.'));
        } catch (Throwable $throwable) {
            $this->danger($throwable->getMessage());
        }

        return $this->redirect();
    }

    /** HR: Ručno izrađuje osobno područje jednog aktivnog korisnika. EN: Manually creates one active user's personal Workspace. */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->body($request);
            $created = $this->personalWorkspaces->ensureForUser(
                $this->positiveInt($body['user_id'] ?? null),
                $this->actorUserId(),
                false,
            );
            if (!is_array($created)) {
                throw new \RuntimeException(__('Osobno područje nije moguće izraditi.'));
            }

            $this->success(__('Osobno područje je izrađeno.'));
        } catch (Throwable $throwable) {
            $this->danger($throwable->getMessage());
        }

        return $this->redirect();
    }

    /**
     * HR: Normalizira tijelo administratorske forme.
     * EN: Normalizes the administrator form body.
     *
     * @return array<string,mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return [];
        }

        $result = [];
        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** HR: Vraća ID prijavljenog administratora. EN: Returns the signed-in administrator ID. */
    private function actorUserId(): int
    {
        $user = $this->authn->userData();
        $id = is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
        if ($id <= 0) {
            throw new \RuntimeException(__('Prijavljeni administrator nije pronađen.'));
        }

        return $id;
    }

    /** HR: Validira pozitivan ID iz forme. EN: Validates a positive form ID. */
    private function positiveInt(mixed $value): int
    {
        $id = is_numeric($value) ? (int)$value : 0;
        if ($id <= 0) {
            throw new \RuntimeException(__('Korisnik nije pronađen.'));
        }

        return $id;
    }

    /** HR: Čita HTML checkbox. EN: Reads an HTML checkbox. */
    private function checked(mixed $value): bool
    {
        return is_scalar($value) && in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** HR: Dodaje uspješnu toast/alert poruku. EN: Adds a successful toast/alert message. */
    private function success(string $message): void
    {
        $this->alerts->add(new Alert($message, AlertLevelEnum::Success));
    }

    /** HR: Dodaje poruku pogreške. EN: Adds an error message. */
    private function danger(string $message): void
    {
        $this->alerts->add(new Alert($message, AlertLevelEnum::Danger));
    }

    /** HR: Vraća se na administratorski pregled. EN: Returns to the administrator overview. */
    private function redirect(): ResponseInterface
    {
        return $this->responses->redirect(
            $this->path('simbioza-user.personal-workspaces.settings', '/settings/personal-workspaces'),
        );
    }

    /** HR: Generira named rutu ili prenosivi fallback. EN: Generates a named route or portable fallback. */
    private function path(string $route, string $fallback): string
    {
        return $this->urls->namedRouteExists($route)
            ? $this->urls->getPathFor($route)
            : rtrim($this->urls->getBasePath(), '/') . $fallback;
    }

    /** HR: Vraća named javni URL područja uz prenosivi fallback. EN: Returns the named public Workspace URL with a portable fallback. */
    private function workspacePath(string $slug): string
    {
        if ($this->urls->namedRouteExists('workspace.show')) {
            return $this->urls->getPathFor('workspace.show', ['workspaceSlug' => $slug]);
        }

        return rtrim($this->urls->getBasePath(), '/') . '/workspace/' . rawurlencode($slug);
    }
}
