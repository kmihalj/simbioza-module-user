<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountSectionRegistry;
use AaiEduHr\HeartPhrameModuleNotification\Account\NotificationAccountSectionProvider;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationVisibilityRegistry;
use AaiEduHr\SimbiozaModuleUser\Account\SimbiozaUserAccountSectionProvider;
use AaiEduHr\SimbiozaModuleUser\Notification\SimbiozaNotificationVisibilityProvider;
use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceIntegrationRegistrarInterface;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspacePresentationRegistry;

/**
 * HR: Na jednome mjestu registrira sve prikazne integracije korisničkog modula.
 *     Poziv je idempotentan pa ga smije pokrenuti modul koji se učita posljednji.
 * EN: Registers every user-module presentation integration in one place. The
 *     call is idempotent so whichever module loads last may safely invoke it.
 */
final readonly class SimbiozaUserIntegrationRegistrar implements WorkspaceIntegrationRegistrarInterface
{
    /** HR: Prima registre i providere koji se povezuju. EN: Receives the registries and providers to connect. */
    public function __construct(
        private WorkspacePresentationRegistry $workspacePresentations,
        private PersonalWorkspacePresentationProvider $personalWorkspacePresentation,
        private AuthAccountSectionRegistry $accountSections,
        private SimbiozaUserAccountSectionProvider $accountSection,
        private NotificationVisibilityRegistry $notificationVisibility,
        private SimbiozaNotificationVisibilityProvider $notificationVisibilityProvider,
        private SimbiozaUserMenuIntegration $menu,
    ) {
    }

    /** HR: Dovršava sve integracije bez dupliciranja zapisa. EN: Completes every integration without duplicates. */
    public function register(): void
    {
        $this->workspacePresentations->register($this->personalWorkspacePresentation);
        $this->accountSections->unregister(NotificationAccountSectionProvider::class);
        $this->accountSections->register($this->accountSection);

        $this->notificationVisibility->register($this->notificationVisibilityProvider);
        $this->menu->register();
    }
}
