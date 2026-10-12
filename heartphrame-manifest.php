<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleAuth\Event\UserAuthenticated;
use AaiEduHr\HeartPhrameModuleAuth\Middleware\RequireAdminOrBootstrapMiddleware;
use AaiEduHr\HeartPhrameModuleAuth\Middleware\RequireAuthenticatedUserMiddleware;
use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleCalendar\Event\CalendarEventChanged;
use AaiEduHr\HeartPhrameModuleCalendar\Event\CalendarFollowChanged;
use AaiEduHr\HeartPhrameModuleComment\Event\CommentChanged;
use AaiEduHr\HeartPhrameModuleNotification\ModuleNotification;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleTask\Event\TaskChanged;
use AaiEduHr\SimbiozaModuleUser\Command\HpSimbiozaUserCommand;
use AaiEduHr\SimbiozaModuleUser\Controller\AdminElevationController;
use AaiEduHr\SimbiozaModuleUser\Controller\PersonalWorkspaceSettingsController;
use AaiEduHr\SimbiozaModuleUser\Controller\SimbiozaUserController;
use AaiEduHr\SimbiozaModuleUser\Listener\ActivateAdministratorAfterLocalLogin;
use AaiEduHr\SimbiozaModuleUser\Listener\CalendarFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\Listener\CalendarFollowChangedListener;
use AaiEduHr\SimbiozaModuleUser\Listener\CommentFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\Listener\CreatePersonalWorkspaceAfterLogin;
use AaiEduHr\SimbiozaModuleUser\Listener\PurgeWorkspaceUserData;
use AaiEduHr\SimbiozaModuleUser\Listener\TaskFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\Listener\WorkspaceFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use AaiEduHr\SimbiozaModuleUser\Service\SimbiozaUserIntegrationRegistrar;
use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspaceContentChanged;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Command\CommandDefinition;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Event\EventListener;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

return new class extends \HeartPhrame\Module\AbstractModuleManifest {
    /** @var list<string> */
    private const REQUIRED_PACKAGES = [
        'aaieduhr/heartphrame-module-auth',
        'aaieduhr/heartphrame-module-orm',
        'aaieduhr/heartphrame-module-notification',
        'aaieduhr/simbioza-module-workspace',
    ];

    /**
     * EN: Requires only the core personal-experience modules.
     *
     * HR: Zahtijeva samo temeljne module osobnog iskustva.
     */
    public function canLoad(ContainerInterface $container): bool
    {
        $composer = $container->get(ComposerBridge::class);
        $config = $container->get(ConfigInterface::class);
        $enabled = $config instanceof ConfigInterface
        ? ($config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [])
        : [];
        foreach (self::REQUIRED_PACKAGES as $package) {
            if (
                !($composer instanceof ComposerBridge)
                || !$composer->isInstalled($package)
                || !in_array($package, $enabled, true)
            ) {
                throw new RuntimeException(
                    'Simbioza user module requires enabled package "' . $package . '" before "'
                    . ModuleSimbiozaUser::PACKAGE_NAME . '".',
                );
            }
        }

        if (
            !class_exists(ModuleAuth::class)
            || !class_exists(Database::class)
            || !class_exists(ModuleNotification::class)
            || !class_exists(ModuleWorkspace::class)
        ) {
            throw new RuntimeException('Simbioza user module dependencies are unavailable.');
        }

        return true;
    }

    /**
     * EN: Loading waits for required modules.
     *
     * HR: Učitavanje čeka obavezne module.
     */
    public function requiresDeferredLoading(): bool
    {
        return true;
    }

    /**
     * EN: Loads module service definitions.
     *
     * HR: Učitava servisne definicije modula.
     */
    public function getServices(): array
    {
        $services = require __DIR__ . '/config/services.php';

        return is_array($services) ? $services : [];
    }

    /**
     * EN: Registers authenticated personal actions and the public CSS asset.
     *
     * HR: Registrira samo autentificirane osobne radnje i javni CSS asset.
     */
    public function getBaseRoutes(): array
    {
        $authenticated = [RequireAuthenticatedUserMiddleware::class];
        $admin = [RequireAdminOrBootstrapMiddleware::class];

        return [
            [
                'POST',
                '/account/following/preferences',
                SimbiozaUserController::class . '@savePreferences',
                'simbioza-user.preferences.save',
                $authenticated,
            ],
            [
                'POST',
                '/account/appearance',
                SimbiozaUserController::class . '@saveThemeMode',
                'simbioza-user.theme-mode.save',
                $authenticated,
            ],
            [
                'POST',
                '/account/personal-workspace',
                SimbiozaUserController::class . '@createPersonalWorkspace',
                'simbioza-user.personal-workspace.create',
                $authenticated,
            ],
            [
                'GET',
                '/account/administrator',
                AdminElevationController::class . '@form',
                'simbioza-user.admin-elevation',
                $authenticated,
            ],
            [
                'POST',
                '/account/administrator/enable',
                AdminElevationController::class . '@enable',
                'simbioza-user.admin-elevation.enable',
                $authenticated,
            ],
            [
                'POST',
                '/account/administrator/disable',
                AdminElevationController::class . '@disable',
                'simbioza-user.admin-elevation.disable',
                $authenticated,
            ],
            [
                'POST',
                '/account/following/toggle',
                SimbiozaUserController::class . '@toggle',
                'simbioza-user.toggle',
                $authenticated,
            ],
            [
                'POST',
                '/account/following/bulk',
                SimbiozaUserController::class . '@bulk',
                'simbioza-user.bulk',
                $authenticated,
            ],
            [
                'POST',
                '/account/following/mode',
                SimbiozaUserController::class . '@setMode',
                'simbioza-user.mode',
                $authenticated,
            ],
            [
                'GET',
                '/account/following/status',
                SimbiozaUserController::class . '@status',
                'simbioza-user.status',
                $authenticated,
            ],
            [
                'GET',
                '/simbioza-user/assets.css',
                SimbiozaUserController::class . '@styles',
                'simbioza-user.assets.css',
                [],
            ],
            [
                'GET',
                '/settings/personal-workspaces',
                PersonalWorkspaceSettingsController::class . '@index',
                'simbioza-user.personal-workspaces.settings',
                $admin,
            ],
            [
                'POST',
                '/settings/personal-workspaces',
                PersonalWorkspaceSettingsController::class . '@save',
                'simbioza-user.personal-workspaces.settings.save',
                $admin,
            ],
        ];
    }

    /**
     * EN: Registers the profile section and removes the duplicated generic e-mail switch.
     *
     * HR: Registrira profilnu cjelinu te uklanja duplicirani generički e-mail prekidač.
     */
    public function getBootstrapCallables(): array
    {
        return [static function (ContainerInterface $container): void {
            try {
                $registrar = $container->get(SimbiozaUserIntegrationRegistrar::class);
                if ($registrar instanceof SimbiozaUserIntegrationRegistrar) {
                    $registrar->register();
                }
            } catch (ContainerExceptionInterface) {
                // EN: Workspace retries the registration when it loads after this module.
                //
                // HR: Workspace će ponoviti registraciju ako se učitava nakon ovog modula.
            }
        }];
    }

    /**
     * EN: Binds required Workspace and installed optional domain events.
     *
     * HR: Veže obavezni Workspace i instalirane opcionalne domenske događaje.
     */
    public function getEventListeners(): array
    {
        $listeners = [
            new EventListener(UserAuthenticated::class, ActivateAdministratorAfterLocalLogin::class),
            new EventListener(UserAuthenticated::class, CreatePersonalWorkspaceAfterLogin::class),
            new EventListener(WorkspaceContentChanged::class, WorkspaceFollowActivityListener::class),
            new EventListener(
                \AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspacePermanentlyDeleting::class,
                PurgeWorkspaceUserData::class,
            ),
        ];
        $optional = [
            CommentChanged::class => CommentFollowActivityListener::class,
            TaskChanged::class => TaskFollowActivityListener::class,
            CalendarEventChanged::class => CalendarFollowActivityListener::class,
            CalendarFollowChanged::class => CalendarFollowChangedListener::class,
        ];
        foreach ($optional as $event => $listener) {
            if (class_exists($event)) {
                $listeners[] = new EventListener($event, $listener);
            }
        }

        return $listeners;
    }

    /**
     * EN: Registers migration installation and the daily-digest worker.
     *
     * HR: Registrira instalaciju migracije i worker dnevnog sažetka.
     */
    public function getCommands(): array
    {
        return [
            new CommandDefinition(
                'simbioza-user',
                'Manage Simbioza personal follows.',
                [HpSimbiozaUserCommand::class, 'run'],
            ),
            new CommandDefinition(
                'simbioza-user:install-migration',
                'Copy the Simbioza user migration.',
                [HpSimbiozaUserCommand::class, 'installMigration'],
            ),
            new CommandDefinition(
                'simbioza-user:dispatch',
                'Dispatch due followed-content digests.',
                [HpSimbiozaUserCommand::class, 'dispatchDigests'],
            ),
        ];
    }

    /**
     * EN: Returns the modular partial directory.
     *
     * HR: Vraća direktorij modularnih partiala.
     */
    public function getViewsPath(): string
    {
        return __DIR__ . '/views';
    }
};
