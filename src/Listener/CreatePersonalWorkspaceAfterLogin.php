<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Listener;

use AaiEduHr\HeartPhrameModuleAuth\Event\UserAuthenticated;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use Psr\Log\LoggerInterface;
use Throwable;

/** HR: Sigurno pokreće automatsku izradu nakon prijave. EN: Safely triggers automatic creation after sign-in. */
final readonly class CreatePersonalWorkspaceAfterLogin
{
    /** HR: Prima poslovni servis i tehnički logger. EN: Receives the business service and technical logger. */
    public function __construct(
        private PersonalWorkspaceService $personalWorkspaces,
        private LoggerInterface $logger,
    ) {
    }

    /** HR: Kvar izrade ne smije prekinuti već uspješnu prijavu. EN: A provisioning failure must not interrupt a successful sign-in. */
    public function __invoke(UserAuthenticated $event): void
    {
        try {
            $this->personalWorkspaces->ensureAfterLogin($event->userId);
        } catch (Throwable $throwable) {
            $this->logger->warning('Personal Workspace provisioning after login failed.', [
                'module' => 'simbioza-user',
                'user_id' => $event->userId,
                'provider' => $event->provider,
                'exception' => $throwable,
            ]);
        }
    }
}
