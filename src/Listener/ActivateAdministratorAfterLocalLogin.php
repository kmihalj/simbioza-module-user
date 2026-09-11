<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Listener;

use AaiEduHr\HeartPhrameModuleAuth\Event\UserAuthenticated;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthSettingsService;
use AaiEduHr\SimbiozaModuleUser\Security\SimbiozaAdminElevationService;
use Psr\Log\LoggerInterface;
use Throwable;

/** HR: Lokalno prijavljenom administratoru odmah uključuje Simbioza admin prava. EN: Immediately elevates a locally signed-in Simbioza administrator. */
final readonly class ActivateAdministratorAfterLocalLogin
{
    /** HR: Prima Simbioza elevaciju i tehnički logger. EN: Receives Simbioza elevation and the technical logger. */
    public function __construct(
        private SimbiozaAdminElevationService $elevation,
        private LoggerInterface $logger,
    ) {
    }

    /** HR: Vanjske prijave ostaju obične dok korisnik ručno ne potvrdi lokalnu lozinku. EN: External sign-ins remain ordinary until the user manually confirms the local password. */
    public function __invoke(UserAuthenticated $event): void
    {
        if ($event->provider !== AuthSettingsService::PROVIDER_LOCAL) {
            return;
        }

        try {
            $this->elevation->activateAfterVerifiedLocalLogin($event->userId);
        } catch (Throwable $throwable) {
            $this->logger->warning('Administrator elevation after local sign-in failed.', [
                'module' => 'simbioza-user',
                'user_id' => $event->userId,
                'exception' => $throwable,
            ]);
        }
    }
}
