<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Controller;

use AaiEduHr\SimbiozaModuleUser\Security\SimbiozaAdminElevationService;
use AaiEduHr\SimbiozaModuleUser\Service\SimbiozaUserModuleViewRenderer;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_array;
use function is_numeric;
use function is_scalar;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function trim;

/** HR: Obrađuje potvrdu i isključivanje privremenih admin ovlasti. EN: Handles confirmation and disabling of temporary admin rights. */
final readonly class AdminElevationController
{
    /**
     * HR: Prima HTTP, prikazne, auth, elevacijske i navigacijske ovisnosti.
     * EN: Receives HTTP, view, auth, elevation, and navigation dependencies.
     */
    public function __construct(
        private ResponseFactory $responses,
        private SimbiozaUserModuleViewRenderer $views,
        private AuthnHandlerInterface $authn,
        private SimbiozaAdminElevationService $elevation,
        private UrlGenerator $urls,
        private AlertHandler $alerts,
    ) {
    }

    /** HR: Prikazuje potvrdu lokalnom lozinkom. EN: Shows local-password confirmation. */
    public function form(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->currentUserId();
        if (!$this->elevation->isAdministratorMember($userId)) {
            return $this->renderDenied(__('Nemate ovlasti za pristup traženom sadržaju.'));
        }

        if (!$this->elevation->hasLocalPassword($userId)) {
            return $this->renderDenied(
                __('Administratorske ovlasti možete uključiti tek nakon postavljanja lokalne lozinke.'),
            );
        }

        $next = $this->safeNext($request->getQueryParams()['next'] ?? null);
        if ($this->elevation->isElevated($userId)) {
            return $this->responses->redirect($next !== '' ? $next : $this->homePath());
        }

        return $this->renderForm($next);
    }

    /** HR: Provjerava lokalnu lozinku i uključuje ovlasti u sessionu. EN: Verifies the local password and enables rights in the session. */
    public function enable(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $password = is_scalar($body['password'] ?? null) ? (string)$body['password'] : '';
        $next = $this->safeNext($body['next'] ?? null);
        $result = $this->elevation->activate($this->currentUserId(), $password);

        if ($result === SimbiozaAdminElevationService::STATUS_ACTIVATED) {
            $this->alerts->add(new Alert(
                __('Administratorske ovlasti su uključene.'),
                AlertLevelEnum::Success,
            ));

            return $this->responses->redirect($next !== '' ? $next : $this->homePath());
        }

        if ($result === SimbiozaAdminElevationService::STATUS_NOT_ADMINISTRATOR) {
            return $this->renderDenied(__('Nemate ovlasti za pristup traženom sadržaju.'));
        }

        if ($result === SimbiozaAdminElevationService::STATUS_MISSING_PASSWORD) {
            return $this->renderDenied(
                __('Administratorske ovlasti možete uključiti tek nakon postavljanja lokalne lozinke.'),
            );
        }

        $message = match ($result) {
            SimbiozaAdminElevationService::STATUS_LOCKED =>
                __('Previše neuspjelih pokušaja. Pričekajte pet minuta i pokušajte ponovno.'),
            default => __('Lokalna lozinka nije ispravna.'),
        };

        return $this->renderForm($next, $message, 422);
    }

    /** HR: Isključuje ovlasti bez traženja lozinke. EN: Disables rights without another password prompt. */
    public function disable(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if (!$this->elevation->isAdministratorMember($userId)) {
            return $this->renderDenied(__('Nemate ovlasti za pristup traženom sadržaju.'));
        }

        $this->elevation->deactivate($userId);
        $this->alerts->add(new Alert(
            __('Administratorske ovlasti su isključene.'),
            AlertLevelEnum::Success,
        ));

        return $this->responses->redirect($this->homePath());
    }

    /** HR: Renderira obrazac za potvrdu lozinke. EN: Renders the password-confirmation form. */
    private function renderForm(string $next, string $error = '', int $status = 200): ResponseInterface
    {
        return $this->views->render('simbioza-user/admin_elevation', [
            'title' => __('Administratorske ovlasti'),
            'error' => $error,
            'next' => $next,
            'enablePath' => $this->path('simbioza-user.admin-elevation.enable', '/account/administrator/enable'),
            'cancelPath' => $next !== '' ? $next : $this->homePath(),
        ], $status);
    }

    /** HR: Renderira odgovor zabrane bez obrasca za potvrdu. EN: Renders a forbidden response without a confirmation form. */
    private function renderDenied(string $message): ResponseInterface
    {
        return $this->views->render('simbioza-user/admin_elevation', [
            'title' => __('Pristup nije dozvoljen'),
            'error' => $message,
            'next' => '',
            'enablePath' => '',
            'cancelPath' => $this->homePath(),
        ], 403);
    }

    /** HR: Vraća ID trenutačno prijavljenog efektivnog korisnika. EN: Returns the current effective authenticated user ID. */
    private function currentUserId(): int
    {
        $user = $this->authn->userData();

        return is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
    }

    /** HR: Prihvaća samo lokalnu relativnu putanju za povratak. EN: Accepts only a local relative return path. */
    private function safeNext(mixed $value): string
    {
        $path = is_scalar($value) ? trim((string)$value) : '';
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            return '';
        }

        $parts = parse_url($path);

        return is_array($parts) && !isset($parts['scheme'], $parts['host']) ? $path : '';
    }

    /** HR: Vraća početnu putanju aplikacije. EN: Returns the application home path. */
    private function homePath(): string
    {
        return $this->path('home', '/');
    }

    /** HR: Generira imenovanu rutu ili prenosivu zamjensku putanju. EN: Generates a named route or a portable fallback path. */
    private function path(string $route, string $fallback): string
    {
        return $this->urls->namedRouteExists($route)
            ? $this->urls->getPathFor($route)
            : rtrim($this->urls->getBasePath(), '/') . $fallback;
    }
}
