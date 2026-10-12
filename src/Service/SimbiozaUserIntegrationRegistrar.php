<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountNavigationRegistry;
use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountSectionRegistry;
use AaiEduHr\HeartPhrameModuleAuth\Auth\AuthUserContextRegistry;
use AaiEduHr\HeartPhrameModuleNotification\Account\NotificationAccountSectionProvider;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationVisibilityRegistry;
use AaiEduHr\SimbiozaModuleUser\Account\AdminElevationAccountNavigationProvider;
use AaiEduHr\SimbiozaModuleUser\Account\PersonalWorkspaceAccountNavigationProvider;
use AaiEduHr\SimbiozaModuleUser\Account\SimbiozaUserAccountSectionProvider;
use AaiEduHr\SimbiozaModuleUser\Notification\SimbiozaNotificationVisibilityProvider;
use AaiEduHr\SimbiozaModuleUser\Security\SimbiozaAdminContextDecorator;
use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceIntegrationRegistrarInterface;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspacePresentationRegistry;

/**
 * EN: Registers every user-module presentation integration in one place. The
 *     call is idempotent so whichever module loads last may safely invoke it.
 *
 * HR: Na jednome mjestu registrira sve prikazne integracije korisničkog modula.
 *     Poziv je idempotentan pa ga smije pokrenuti modul koji se učita posljednji.
 */
final readonly class SimbiozaUserIntegrationRegistrar implements WorkspaceIntegrationRegistrarInterface
{
    /**
     * EN: Receives the registries and providers to connect.
     *
     * HR: Prima registre i providere koji se povezuju.
     */
    public function __construct(
        private WorkspacePresentationRegistry $workspacePresentations,
        private PersonalWorkspacePresentationProvider $personalWorkspacePresentation,
        private AuthAccountSectionRegistry $accountSections,
        private SimbiozaUserAccountSectionProvider $accountSection,
        private AuthAccountNavigationRegistry $accountNavigation,
        private PersonalWorkspaceAccountNavigationProvider $personalWorkspaceNavigation,
        private AdminElevationAccountNavigationProvider $adminElevationNavigation,
        private AuthUserContextRegistry $authUserContexts,
        private SimbiozaAdminContextDecorator $adminContextDecorator,
        private NotificationVisibilityRegistry $notificationVisibility,
        private SimbiozaNotificationVisibilityProvider $notificationVisibilityProvider,
        private SimbiozaUserMenuIntegration $menu,
        private UserThemeViewIntegration $userTheme,
    ) {
    }

    /**
     * EN: Completes every integration without duplicates.
     *
     * HR: Dovršava sve integracije bez dupliciranja zapisa.
     */
    public function register(): void
    {
        $this->workspacePresentations->register($this->personalWorkspacePresentation);
        $this->accountSections->unregister(NotificationAccountSectionProvider::class);
        $this->accountSections->register($this->accountSection);

        $this->accountNavigation->register($this->personalWorkspaceNavigation);
        $this->accountNavigation->register($this->adminElevationNavigation);

        $this->authUserContexts->register($this->adminContextDecorator);

        $this->notificationVisibility->register($this->notificationVisibilityProvider);
        $this->menu->register();
        $this->userTheme->register();
    }
}
