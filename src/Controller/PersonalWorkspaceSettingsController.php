<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Controller;

use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use AaiEduHr\SimbiozaModuleUser\Service\SimbiozaUserModuleViewRenderer;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspacePresentationRegistry;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
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

/**
 * EN: Administrator HTTP adapter for personal Workspaces.
 *
 * HR: Administratorski HTTP adapter za osobna područja.
 */
final readonly class PersonalWorkspaceSettingsController
{
    /**
     * EN: Receives only public business and framework services.
     *
     * HR: Prima samo javne poslovne i framework servise.
     */
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

    /**
     * EN: Displays global rules and the created personal-Workspace overview.
     *
     * HR: Prikazuje globalna pravila i pregled izrađenih osobnih područja.
     */
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
            'selfCreationEnabled' => $this->personalWorkspaces->selfCreationEnabled(),
            'users' => $users,
            'settingsMenuActiveSection' => 'simbioza-user.personal-workspaces.settings',
            'savePath' => $this->path(
                'simbioza-user.personal-workspaces.settings.save',
                '/settings/personal-workspaces',
            ),
        ]);
    }

    /**
     * EN: Saves global automatic and user-driven creation rules.
     *
     * HR: Sprema globalna pravila automatske i korisničke izrade.
     */
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->body($request);
            $this->personalWorkspaces->setCreationSettings(
                $this->checked($body['auto_create_enabled'] ?? $body['enabled'] ?? null),
                $this->checked($body['self_create_enabled'] ?? null),
                $this->actorUserId(),
            );
            $this->success(__('Postavke osobnih područja su spremljene.'));
        } catch (Throwable $throwable) {
            $this->danger($throwable->getMessage());
        }

        return $this->redirect();
    }

    /**
     * EN: Normalizes the administrator form body.
     *
     * HR: Normalizira tijelo administratorske forme.
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

    /**
     * EN: Returns the signed-in administrator ID.
     *
     * HR: Vraća ID prijavljenog administratora.
     */
    private function actorUserId(): int
    {
        $user = $this->authn->userData();
        $id = is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
        if ($id <= 0) {
            throw new \RuntimeException(__('Prijavljeni administrator nije pronađen.'));
        }

        return $id;
    }

    /**
     * EN: Reads an HTML checkbox.
     *
     * HR: Čita HTML checkbox.
     */
    private function checked(mixed $value): bool
    {
        return is_scalar($value) && in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * EN: Adds a successful toast/alert message.
     *
     * HR: Dodaje uspješnu toast/alert poruku.
     */
    private function success(string $message): void
    {
        $this->alerts->add(new Alert($message, AlertLevelEnum::Success));
    }

    /**
     * EN: Adds an error message.
     *
     * HR: Dodaje poruku pogreške.
     */
    private function danger(string $message): void
    {
        $this->alerts->add(new Alert($message, AlertLevelEnum::Danger));
    }

    /**
     * EN: Returns to the administrator overview.
     *
     * HR: Vraća se na administratorski pregled.
     */
    private function redirect(): ResponseInterface
    {
        return $this->responses->redirect(
            $this->path('simbioza-user.personal-workspaces.settings', '/settings/personal-workspaces'),
        );
    }

    /**
     * EN: Generates a named route or portable fallback.
     *
     * HR: Generira named rutu ili prenosivi fallback.
     */
    private function path(string $route, string $fallback): string
    {
        return $this->urls->namedRouteExists($route)
            ? $this->urls->getPathFor($route)
            : rtrim($this->urls->getBasePath(), '/') . $fallback;
    }

    /**
     * EN: Returns the named public Workspace URL with a portable fallback.
     *
     * HR: Vraća named javni URL područja uz prenosivi fallback.
     */
    private function workspacePath(string $slug): string
    {
        if ($this->urls->namedRouteExists('workspace.show')) {
            return $this->urls->getPathFor('workspace.show', ['workspaceSlug' => $slug]);
        }

        return rtrim($this->urls->getBasePath(), '/') . '/workspace/' . rawurlencode($slug);
    }
}
