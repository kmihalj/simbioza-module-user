<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountSectionRegistry;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorPublishedVersionProviderInterface;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationPreferenceService;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationService;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationVisibilityRegistry;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleUser\Account\SimbiozaUserAccountSectionProvider;
use AaiEduHr\SimbiozaModuleUser\Api\SimbiozaUserApiExtension;
use AaiEduHr\SimbiozaModuleUser\Api\SimbiozaUserResourceController;
use AaiEduHr\SimbiozaModuleUser\Backup\SimbiozaUserWorkspaceBackupProvider;
use AaiEduHr\SimbiozaModuleUser\Command\HpSimbiozaUserCommand;
use AaiEduHr\SimbiozaModuleUser\Controller\PersonalWorkspaceSettingsController;
use AaiEduHr\SimbiozaModuleUser\Controller\SimbiozaUserController;
use AaiEduHr\SimbiozaModuleUser\Listener\CalendarFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\Listener\CalendarFollowChangedListener;
use AaiEduHr\SimbiozaModuleUser\Listener\CommentFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\Listener\CreatePersonalWorkspaceAfterLogin;
use AaiEduHr\SimbiozaModuleUser\Listener\PurgeWorkspaceUserData;
use AaiEduHr\SimbiozaModuleUser\Listener\TaskFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\Listener\WorkspaceFollowActivityListener;
use AaiEduHr\SimbiozaModuleUser\Notification\SimbiozaNotificationVisibilityProvider;
use AaiEduHr\SimbiozaModuleUser\Service\CalendarSubscriptionSynchronizer;
use AaiEduHr\SimbiozaModuleUser\Service\EmbeddedCalendarPageResolver;
use AaiEduHr\SimbiozaModuleUser\Service\FollowDeliveryService;
use AaiEduHr\SimbiozaModuleUser\Service\FollowService;
use AaiEduHr\SimbiozaModuleUser\Service\FollowTargetService;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspacePresentationProvider;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use AaiEduHr\SimbiozaModuleUser\Service\SimbiozaUserIntegrationRegistrar;
use AaiEduHr\SimbiozaModuleUser\Service\SimbiozaUserMenuIntegration;
use AaiEduHr\SimbiozaModuleUser\Service\SimbiozaUserModuleViewRenderer;
use AaiEduHr\SimbiozaModuleUser\Service\UserPreferenceService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspacePresentationRegistry;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use HeartPhrame\View\CsrfHandler;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

$services = [
    PurgeWorkspaceUserData::class => static fn(ContainerInterface $container): PurgeWorkspaceUserData =>
        new PurgeWorkspaceUserData($container->get(Database::class)),
    PersonalWorkspaceService::class => static fn(ContainerInterface $container): PersonalWorkspaceService =>
        new PersonalWorkspaceService(
            $container->get(Database::class),
            $container->get(WorkspaceRepository::class),
            $container->get(AuthUserService::class),
            $container->get(EventDispatcherInterface::class),
        ),

    PersonalWorkspacePresentationProvider::class =>
        static fn(ContainerInterface $container): PersonalWorkspacePresentationProvider =>
            new PersonalWorkspacePresentationProvider(
                $container->get(PersonalWorkspaceService::class),
                $container->get(\HeartPhrame\Localization\TranslatorInterface::class),
            ),

    SimbiozaUserModuleViewRenderer::class =>
        static fn(ContainerInterface $container): SimbiozaUserModuleViewRenderer =>
            new SimbiozaUserModuleViewRenderer($container->get(ResponseFactory::class)),

    SimbiozaUserMenuIntegration::class =>
        static fn(ContainerInterface $container): SimbiozaUserMenuIntegration =>
            new SimbiozaUserMenuIntegration($container, $container->get(ConfigInterface::class)),

    SimbiozaUserIntegrationRegistrar::class =>
        static fn(ContainerInterface $container): SimbiozaUserIntegrationRegistrar =>
            new SimbiozaUserIntegrationRegistrar(
                $container->get(WorkspacePresentationRegistry::class),
                $container->get(PersonalWorkspacePresentationProvider::class),
                $container->get(AuthAccountSectionRegistry::class),
                $container->get(SimbiozaUserAccountSectionProvider::class),
                $container->get(NotificationVisibilityRegistry::class),
                $container->get(SimbiozaNotificationVisibilityProvider::class),
                $container->get(SimbiozaUserMenuIntegration::class),
            ),

    PersonalWorkspaceSettingsController::class =>
        static fn(ContainerInterface $container): PersonalWorkspaceSettingsController =>
            new PersonalWorkspaceSettingsController(
                $container->get(ResponseFactory::class),
                $container->get(SimbiozaUserModuleViewRenderer::class),
                $container->get(PersonalWorkspaceService::class),
                $container->get(AuthnHandlerInterface::class),
                $container->get(UrlGenerator::class),
                $container->get(AlertHandler::class),
                $container->get(WorkspacePresentationRegistry::class),
            ),

    CreatePersonalWorkspaceAfterLogin::class =>
        static fn(ContainerInterface $container): CreatePersonalWorkspaceAfterLogin =>
            new CreatePersonalWorkspaceAfterLogin(
                $container->get(PersonalWorkspaceService::class),
                $container->get(LoggerInterface::class),
            ),

    UserPreferenceService::class => static fn(ContainerInterface $container): UserPreferenceService =>
        new UserPreferenceService($container->get(Database::class)),

    FollowTargetService::class => static fn(ContainerInterface $container): FollowTargetService =>
        new FollowTargetService(
            $container->get(Database::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceConfig::class),
            $container->get(\HeartPhrame\Localization\TranslatorInterface::class),
            $container->get(UrlGenerator::class),
            $container,
        ),

    FollowService::class => static fn(ContainerInterface $container): FollowService =>
        new FollowService(
            $container->get(Database::class),
            $container->get(FollowTargetService::class),
            $container->get(UserPreferenceService::class),
        ),

    SimbiozaNotificationVisibilityProvider::class =>
        static fn(ContainerInterface $container): SimbiozaNotificationVisibilityProvider =>
            new SimbiozaNotificationVisibilityProvider($container->get(FollowTargetService::class)),

    FollowDeliveryService::class => static fn(ContainerInterface $container): FollowDeliveryService =>
        new FollowDeliveryService(
            $container->get(Database::class),
            $container->get(FollowService::class),
            $container->get(FollowTargetService::class),
            $container->get(UserPreferenceService::class),
            $container->get(NotificationPreferenceService::class),
            $container->get(NotificationService::class),
            $container,
            $container->get(LoggerInterface::class),
        ),

    SimbiozaUserController::class => static fn(ContainerInterface $container): SimbiozaUserController =>
        new SimbiozaUserController(
            $container->get(ResponseFactory::class),
            $container->get(AuthnHandlerInterface::class),
            $container->get(FollowService::class),
            $container->get(UserPreferenceService::class),
            $container->get(NotificationPreferenceService::class),
            $container->get(UrlGenerator::class),
            $container->get(AlertHandler::class),
            $container->get(CsrfHandler::class),
            $container->get(EventDispatcherInterface::class),
        ),

    SimbiozaUserAccountSectionProvider::class =>
        static function (ContainerInterface $container): SimbiozaUserAccountSectionProvider {
            $calendarSubscriptions = class_exists(\AaiEduHr\HeartPhrameModuleCalendar\Event\CalendarEventChanged::class)
                ? $container->get(CalendarSubscriptionSynchronizer::class)
                : null;

            return new SimbiozaUserAccountSectionProvider(
                $container->get(FollowService::class),
                $container->get(UserPreferenceService::class),
                $container->get(NotificationPreferenceService::class),
                $container->get(UrlGenerator::class),
                $calendarSubscriptions,
                $container->get(PersonalWorkspaceService::class),
                $container->get(WorkspacePresentationRegistry::class),
            );
        },

    WorkspaceFollowActivityListener::class =>
        static fn(ContainerInterface $container): WorkspaceFollowActivityListener =>
            new WorkspaceFollowActivityListener(
                $container->get(FollowDeliveryService::class),
                $container->get(WorkspaceRepository::class),
                $container->get(WorkspaceConfig::class),
            ),

    HpSimbiozaUserCommand::class => static fn(ContainerInterface $container): HpSimbiozaUserCommand =>
        new HpSimbiozaUserCommand(
            $container->get(ConfigInterface::class),
            $container->get(FollowDeliveryService::class),
        ),
];

if (class_exists(\AaiEduHr\HeartPhrameModuleComment\Event\CommentChanged::class)) {
    $services[CommentFollowActivityListener::class] =
    static fn(ContainerInterface $container): CommentFollowActivityListener =>
            new CommentFollowActivityListener(
                $container->get(FollowDeliveryService::class),
                $container->get(WorkspaceRepository::class),
            );
}

if (class_exists(\AaiEduHr\HeartPhrameModuleTask\Event\TaskChanged::class)) {
    $services[TaskFollowActivityListener::class] =
    static fn(ContainerInterface $container): TaskFollowActivityListener =>
            new TaskFollowActivityListener(
                $container->get(FollowDeliveryService::class),
                $container->get(WorkspaceRepository::class),
            );
}

if (class_exists(\AaiEduHr\HeartPhrameModuleCalendar\Event\CalendarEventChanged::class)) {
    $services[EmbeddedCalendarPageResolver::class] =
    static fn(ContainerInterface $container): EmbeddedCalendarPageResolver =>
            new EmbeddedCalendarPageResolver(
                $container->get(Database::class),
                $container->get(EditorPublishedVersionProviderInterface::class),
            );
    $services[CalendarSubscriptionSynchronizer::class] =
    static fn(ContainerInterface $container): CalendarSubscriptionSynchronizer =>
            new CalendarSubscriptionSynchronizer(
                $container->get(Database::class),
                $container->get(FollowService::class),
            );
    $services[CalendarFollowActivityListener::class] =
    static fn(ContainerInterface $container): CalendarFollowActivityListener =>
            new CalendarFollowActivityListener(
                $container->get(FollowDeliveryService::class),
                $container->get(CalendarSubscriptionSynchronizer::class),
                $container->get(EmbeddedCalendarPageResolver::class),
            );
}

if (class_exists(\AaiEduHr\HeartPhrameModuleCalendar\Event\CalendarFollowChanged::class)) {
    $services[CalendarFollowChangedListener::class] =
    static fn(ContainerInterface $container): CalendarFollowChangedListener =>
            new CalendarFollowChangedListener($container->get(CalendarSubscriptionSynchronizer::class));
}

// HR: Modul sam oglašava osobni API kada je generička API jezgra dostupna.
// EN: The module advertises its personal API when the generic API core is available.
if (interface_exists(\AaiEduHr\HeartPhrameModuleApi\Contract\ApiExtensionInterface::class)) {
    $services[SimbiozaUserApiExtension::class] =
    static fn(): SimbiozaUserApiExtension => new SimbiozaUserApiExtension();
    $services[SimbiozaUserResourceController::class] =
    static fn(ContainerInterface $container): SimbiozaUserResourceController =>
            new SimbiozaUserResourceController(
                $container->get(\AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory::class),
                $container->get(FollowService::class),
                $container->get(UserPreferenceService::class),
                $container->get(NotificationPreferenceService::class),
                $container->get(PersonalWorkspaceService::class),
                $container->get(WorkspacePresentationRegistry::class),
                $container->has(CalendarSubscriptionSynchronizer::class)
                    ? $container->get(CalendarSubscriptionSynchronizer::class)
                    : null,
                $container->get(EventDispatcherInterface::class),
            );
}

if (class_exists(\AaiEduHr\HeartPhrameModuleBackup\Service\DatabaseTableBackupProvider::class)) {
    $simbiozaUserBackupDependencies = static function (ContainerInterface $container): array {
        $dependencies = ['auth', 'workspace'];
        $config = $container->get(ConfigInterface::class);
        $enabled = $config instanceof ConfigInterface
        ? ($config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [])
        : [];
        if (
            in_array('aaieduhr/heartphrame-module-calendar', $enabled, true)
            && class_exists(\AaiEduHr\HeartPhrameModuleCalendar\ModuleCalendar::class)
        ) {
            $dependencies[] = 'calendar';
        }

        return $dependencies;
    };

    $services['heartphrame.backup.provider.simbioza-user'] =
    static fn(
            ContainerInterface $container,
        ): \AaiEduHr\HeartPhrameModuleBackup\Service\DatabaseTableBackupProvider =>
            new \AaiEduHr\HeartPhrameModuleBackup\Service\DatabaseTableBackupProvider(
                $container->get(Database::class),
                new \AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata(
                    'simbioza-user',
                    \AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser::PACKAGE_NAME,
                    1,
                    ['hr' => 'Osobna praćenja i postavke', 'en' => 'Personal follows and preferences'],
                    $simbiozaUserBackupDependencies($container),
                    [
                        \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::SITE,
                        \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::COMPONENT,
                    ],
                    true,
                    true,
                    componentGroups: [\AaiEduHr\HeartPhrameModuleBackup\Value\BackupComponentGroup::USERS],
                ),
                [
                    [
                        'dataset' => 'preferences',
                        'table' => \AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser::TABLE_PREFERENCES,
                        'primary_key' => 'id',
                        'conflict_keys' => ['user_id'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [[
                            'column' => 'user_id',
                            'namespace' => 'auth.user',
                            'skip_if_missing' => true,
                        ]],
                    ],
                    [
                        'dataset' => 'follows',
                        'table' => \AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser::TABLE_FOLLOWS,
                        'primary_key' => 'id',
                        'conflict_keys' => ['user_id', 'target_type', 'target_id'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [
                            ['column' => 'user_id', 'namespace' => 'auth.user', 'skip_if_missing' => true],
                            ['column' => 'workspace_id', 'namespace' => 'workspace.workspace', 'nullable' => true],
                            ['column' => 'page_id', 'namespace' => 'workspace.node', 'nullable' => true],
                        ],
                        'polymorphic_foreign_keys' => [
                            [
                                'column' => 'target_id',
                                'type_column' => 'target_type',
                                'namespaces' => [
                                    'workspace' => 'workspace.workspace',
                                    'page' => 'workspace.node',
                                    'calendar' => ['calendar.calendar', 'calendar-workspace.calendar'],
                                ],
                                'passthrough' => ['task_list'],
                                'skip_if_missing' => true,
                            ],
                        ],
                    ],
                    [
                        'dataset' => 'follow-exclusions',
                        'table' => \AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS,
                        'primary_key' => 'id',
                        'conflict_keys' => ['user_id', 'target_type', 'target_id'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [
                            ['column' => 'user_id', 'namespace' => 'auth.user', 'skip_if_missing' => true],
                        ],
                        'polymorphic_foreign_keys' => [
                            [
                                'column' => 'target_id',
                                'type_column' => 'target_type',
                                'namespaces' => [
                                    'workspace' => 'workspace.workspace',
                                    'page' => 'workspace.node',
                                    'calendar' => ['calendar.calendar', 'calendar-workspace.calendar'],
                                ],
                                'passthrough' => ['task_list'],
                                'skip_if_missing' => true,
                            ],
                        ],
                    ],
                    [
                        'dataset' => 'personal-workspace-policies',
                        'table' => \AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACE_POLICIES,
                        'primary_key' => 'id',
                        'conflict_keys' => ['user_id'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [
                            ['column' => 'user_id', 'namespace' => 'auth.user', 'skip_if_missing' => true],
                            [
                                'column' => 'updated_by_user_id',
                                'namespace' => 'auth.user',
                                'nullable' => true,
                                'defer' => true,
                            ],
                        ],
                    ],
                    [
                        'dataset' => 'personal-workspaces',
                        'table' => \AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES,
                        'primary_key' => 'id',
                        'conflict_keys' => ['user_id'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [
                            ['column' => 'user_id', 'namespace' => 'auth.user', 'skip_if_missing' => true],
                            [
                                'column' => 'workspace_id',
                                'namespace' => 'workspace.workspace',
                                'skip_if_missing' => true,
                            ],
                        ],
                    ],
                ],
            );

    $services['heartphrame.backup.provider.simbioza-user-settings'] =
    static fn(
            ContainerInterface $container,
        ): \AaiEduHr\HeartPhrameModuleBackup\Service\DatabaseTableBackupProvider =>
            new \AaiEduHr\HeartPhrameModuleBackup\Service\DatabaseTableBackupProvider(
                $container->get(Database::class),
                new \AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata(
                    'simbioza-user-settings',
                    \AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser::PACKAGE_NAME,
                    1,
                    ['hr' => 'Postavke osobnih područja', 'en' => 'Personal-space settings'],
                    [],
                    [
                        \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::SITE,
                        \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::COMPONENT,
                    ],
                    true,
                    true,
                    componentGroups: [\AaiEduHr\HeartPhrameModuleBackup\Value\BackupComponentGroup::SETTINGS],
                ),
                [[
                    'dataset' => 'settings',
                    'table' => \AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser::TABLE_SETTINGS,
                    'primary_key' => 'id',
                    'conflict_keys' => ['setting_key'],
                    'preserve_primary_key' => false,
                ]],
            );

    $workspaceFollowProvider = static function (
        ContainerInterface $container,
        string $id,
        string $scope,
        string $workspaceDependency,
        string $calendarDependency,
    ): SimbiozaUserWorkspaceBackupProvider {
        $dependencies = [$workspaceDependency];
        $calendarPages = null;
        $config = $container->get(ConfigInterface::class);
        $enabled = $config instanceof ConfigInterface
        ? ($config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [])
        : [];
        if (
            in_array('aaieduhr/heartphrame-module-calendar', $enabled, true)
            && class_exists(\AaiEduHr\HeartPhrameModuleCalendar\ModuleCalendar::class)
        ) {
            $dependencies[] = $calendarDependency;
            $calendarPages = $container->get(EmbeddedCalendarPageResolver::class);
        }

        return new SimbiozaUserWorkspaceBackupProvider(
            $container->get(Database::class),
            $container->get(\AaiEduHr\HeartPhrameModuleAuth\Backup\AuthBackupIdentityResolver::class),
            $id,
            $dependencies,
            [$scope],
            $calendarPages instanceof EmbeddedCalendarPageResolver ? $calendarPages : null,
        );
    };

    $services['heartphrame.backup.provider.simbioza-user-workspaces'] =
    static fn(ContainerInterface $container): SimbiozaUserWorkspaceBackupProvider => $workspaceFollowProvider(
        $container,
        'simbioza-user-workspaces',
        \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::COMPONENT,
        'workspace',
        'calendar',
    );

    $services['heartphrame.backup.provider.simbioza-user-workspace'] =
    static fn(ContainerInterface $container): SimbiozaUserWorkspaceBackupProvider => $workspaceFollowProvider(
        $container,
        'simbioza-user-workspace',
        \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::WORKSPACE,
        'workspace-scope',
        'calendar-workspace',
    );
}

return $services;
