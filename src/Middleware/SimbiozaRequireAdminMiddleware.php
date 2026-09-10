<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Middleware;

use AaiEduHr\HeartPhrameModuleAuth\Middleware\RequireAdminOrBootstrapMiddleware;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthSettingsService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\SimbiozaModuleUser\Security\SimbiozaAdminElevationService;
use AaiEduHr\SimbiozaModuleUser\Service\SimbiozaUserModuleViewRenderer;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function is_array;
use function is_numeric;

/**
 * HR: Na Simbioza admin rutama zamjenjuje trajni admin status potvrdom lokalne lozinke.
 * EN: Replaces persistent admin status with a local-password challenge on Simbioza admin routes.
 */
final readonly class SimbiozaRequireAdminMiddleware implements MiddlewareInterface
{
    /**
     * HR: Prima sirovi Auth kontekst, stanje elevacije i standardni fallback.
     * EN: Receives the raw Auth context, elevation state, and standard fallback.
     */
    public function __construct(
        private AuthnHandlerInterface $authn,
        private SimbiozaAdminElevationService $elevation,
        private AuthSettingsService $settings,
        private AuthUserService $users,
        private RequireAdminOrBootstrapMiddleware $fallback,
        private SimbiozaUserModuleViewRenderer $views,
        private ResponseFactory $responses,
        private UrlGenerator $urls,
    ) {
    }

    /**
     * HR: Propušta samo aktivno potvrđenog administratora ili bootstrap instalaciju.
     * EN: Allows only an actively confirmed administrator or a bootstrap installation.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->settings->isSchemaReady() || $this->users->countUsers() === 0) {
            return $this->fallback->process($request, $handler);
        }

        $user = $this->authn->user();
        if (!is_array($user)) {
            return $this->fallback->process($request, $handler);
        }

        $userId = is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
        if (!$this->elevation->isAdministratorMember($userId)) {
            return $this->fallback->process($request, $handler);
        }

        if ($this->elevation->isElevated($userId)) {
            return $handler->handle($request);
        }

        $next = $request->getUri()->getPath();
        if ($request->getUri()->getQuery() !== '') {
            $next .= '?' . $request->getUri()->getQuery();
        }

        if ($this->elevation->hasLocalPassword($userId)) {
            $path = $this->urls->namedRouteExists('simbioza-user.admin-elevation')
                ? $this->urls->getPathFor('simbioza-user.admin-elevation', [], ['next' => $next])
                : rtrim($this->urls->getBasePath(), '/')
                    . '/account/administrator?next=' . rawurlencode($next);

            return $this->responses->redirect($path);
        }

        $home = $this->urls->namedRouteExists('home') ? $this->urls->getPathFor('home') : '/';
        return $this->views->render('simbioza-user/admin_elevation', [
            'title' => __('Pristup nije dozvoljen'),
            'error' => __('Prije korištenja administratorskih ovlasti morate postaviti lokalnu lozinku.'),
            'next' => '',
            'enablePath' => '',
            'cancelPath' => $home,
        ], 403);
    }
}
