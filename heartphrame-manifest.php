<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountSectionRegistry;
use AaiEduHr\HeartPhrameModuleCalendar\Event\CalendarEventChanged;
use AaiEduHr\HeartPhrameModuleCalendar\Event\CalendarFollowChanged;
use AaiEduHr\HeartPhrameModuleAuth\Middleware\RequireAuthenticatedUserMiddleware;
use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleComment\Event\CommentChanged;
use AaiEduHr\HeartPhrameModuleNotification\Account\NotificationAccountSectionProvider;
use AaiEduHr\HeartPhrameModuleNotification\ModuleNotification;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleTask\Event\TaskChanged;
use AaiEduHr\HeartPhrameModuleWorkspace\Event\WorkspaceContentChanged;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleUser\Account\SimbiozaUserAccountSectionProvider;
use AaiEduHr\SimbiozaModuleUser\Command\HpSimbiozaUserCommand;
use AaiEduHr\SimbiozaModuleUser\Controller\SimbiozaUserController;
use AaiEduHr\SimbiozaModuleUser\Listener\CalendarFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\Listener\CalendarFollowChangedListener;
use AaiEduHr\SimbiozaModuleUser\Listener\CommentFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\Listener\TaskFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\Listener\WorkspaceFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use AaiEduHr\SimbiozaModuleUser\Notification\SimbiozaNotificationVisibilityProvider;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Command\CommandDefinition;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Event\EventListener;
use Psr\Container\ContainerInterface;

return new class extends \HeartPhrame\Module\AbstractModuleManifest {
    /** @var list<string> */
    private const REQUIRED_PACKAGES = [
        'aaieduhr/heartphrame-module-auth',
        'aaieduhr/heartphrame-module-orm',
        'aaieduhr/heartphrame-module-notification',
        'aaieduhr/heartphrame-module-workspace',
    ];

    /** HR: Zahtijeva samo temeljne module osobnog iskustva. EN: Requires only the core personal-experience modules. */
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

    /** HR: Učitavanje čeka obavezne module. EN: Loading waits for required modules. */
    public function requiresDeferredLoading(): bool
    {
        return true;
    }

    /** HR: Učitava servisne definicije modula. EN: Loads module service definitions. */
    public function getServices(): array
    {
        $services = require __DIR__ . '/config/services.php';

        return is_array($services) ? $services : [];
    }

    /**
     * HR: Registrira samo autentificirane osobne radnje i javni CSS asset.
     * EN: Registers authenticated personal actions and the public CSS asset.
     */
    public function getBaseRoutes(): array
    {
        $authenticated = [RequireAuthenticatedUserMiddleware::class];

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
        ];
    }

    /** HR: Registrira profilnu cjelinu te uklanja duplicirani generički e-mail prekidač. EN: Registers the profile section and removes the duplicated generic e-mail switch. */
    public function getBootstrapCallables(): array
    {
        return [static function (ContainerInterface $container): void {
            $registry = $container->get(AuthAccountSectionRegistry::class);
            $provider = $container->get(SimbiozaUserAccountSectionProvider::class);
            if ($registry instanceof AuthAccountSectionRegistry) {
                $registry->unregister(NotificationAccountSectionProvider::class);

                if ($provider instanceof SimbiozaUserAccountSectionProvider) {
                    $registry->register($provider);
                }
            }

            $visibility = $container->get(
                \AaiEduHr\HeartPhrameModuleNotification\Service\NotificationVisibilityRegistry::class,
            );
            $visibilityProvider = $container->get(SimbiozaNotificationVisibilityProvider::class);
            if (
                $visibility instanceof \AaiEduHr\HeartPhrameModuleNotification\Service\NotificationVisibilityRegistry
                && $visibilityProvider instanceof SimbiozaNotificationVisibilityProvider
            ) {
                $visibility->register($visibilityProvider);
            }
        }];
    }

    /** HR: Veže obavezni Workspace i instalirane opcionalne domenske događaje. EN: Binds required Workspace and installed optional domain events. */
    public function getEventListeners(): array
    {
        $listeners = [
            new EventListener(WorkspaceContentChanged::class, WorkspaceFollowActivityListener::class),
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
     * HR: Registrira instalaciju migracije i worker dnevnog sažetka.
     * EN: Registers migration installation and the daily-digest worker.
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

    /** HR: Vraća direktorij modularnih partiala. EN: Returns the modular partial directory. */
    public function getViewsPath(): string
    {
        return __DIR__ . '/views';
    }
};
