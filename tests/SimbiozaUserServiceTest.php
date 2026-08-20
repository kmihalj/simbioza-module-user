<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Tests;

use AaiEduHr\HeartPhrameModuleAuth\Backup\AuthBackupIdentityResolver;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\HeartPhrameModuleCalendar\ModuleCalendar;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationEmailBridge;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationPreferenceService;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationService;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationVisibilityRegistry;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\SimbiozaModuleUser\Backup\SimbiozaUserWorkspaceBackupProvider;
use AaiEduHr\SimbiozaModuleUser\Contract\FollowTargetResolverInterface;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use AaiEduHr\SimbiozaModuleUser\Notification\SimbiozaNotificationVisibilityProvider;
use AaiEduHr\SimbiozaModuleUser\Service\CalendarSubscriptionSynchronizer;
use AaiEduHr\SimbiozaModuleUser\Service\FollowDeliveryService;
use AaiEduHr\SimbiozaModuleUser\Service\FollowService;
use AaiEduHr\SimbiozaModuleUser\Service\UserPreferenceService;
use AaiEduHr\SimbiozaModuleUser\Value\FollowActivity;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(FollowService::class)]
#[CoversClass(UserPreferenceService::class)]
#[CoversClass(FollowDeliveryService::class)]
#[CoversClass(CalendarSubscriptionSynchronizer::class)]
#[CoversClass(SimbiozaNotificationVisibilityProvider::class)]
#[CoversClass(SimbiozaUserWorkspaceBackupProvider::class)]
#[UsesClass(FollowActivity::class)]
#[UsesClass(NotificationEmailBridge::class)]
#[UsesClass(NotificationPreferenceService::class)]
#[UsesClass(NotificationService::class)]
#[UsesClass(NotificationVisibilityRegistry::class)]
final class SimbiozaUserServiceTest extends TestCase
{
    private Database $database;

    private FollowTargetResolverInterface $targets;

    /**
     * HR: Priprema prenosive SQLite tablice i kontrolirani ACL resolver.
     * EN: Prepares portable SQLite tables and a controlled ACL resolver.
     */
    protected function setUp(): void
    {
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]);
        $this->database = new Database($config, $helper);
        $this->runMigration(
            dirname(__DIR__) . '/resources/migrations/initial_simbioza_user_schema.php',
        );
        $notificationMigration = $this->resolveNotificationMigrationPath('initial_notification_schema.php');
        $this->runMigration($notificationMigration);
        $this->targets = new class implements FollowTargetResolverInterface {
            public bool $accessible = true;

            /** {@inheritDoc} */
            public function describe(string $type, string $id, int $userId, array $context = []): array
            {
                return [
                    'accessible' => $this->accessible && $userId > 0,
                    'type' => $type,
                    'id' => $id,
                    'label' => 'Target ' . $id,
                    'url' => '/target/' . $id,
                    'workspace_id' => $type === 'workspace' ? (int)$id : 12,
                    'page_id' => $type === 'page' ? (int)$id : null,
                    'document_id' => $context['document_id'] ?? 'doc-1',
                ];
            }
        };
    }

    /**
     * HR: Potvrđuje spremanje, popis, pronalazak pretplatnika i prestanak praćenja.
     * EN: Verifies persistence, listing, subscriber matching, and unfollowing.
     */
    public function testFollowLifecycleUsesCurrentAcl(): void
    {
        $preferences = new UserPreferenceService($this->database);
        $follows = new FollowService($this->database, $this->targets, $preferences);
        $saved = $follows->follow(7, 'page', '42', ['document_id' => 'doc-42']);

        $this->assertTrue((bool)$saved['accessible']);
        $this->assertSame('Target 42', $saved['label']);
        $this->assertTrue($follows->isFollowing(7, 'page', '42'));
        $this->assertCount(1, $follows->listForUser(7));
        $this->assertCount(1, $follows->matchingFollows('page', '42', 12, 42));

        $this->setTargetAccess(false);
        $this->assertFalse((bool)$follows->listForUser(7)[0]['accessible']);
        $this->assertTrue($follows->unfollow(7, 'page', '42'));
        $this->assertFalse($follows->isFollowing(7, 'page', '42'));
    }

    /**
     * HR: Praćenje jedne liste ne smije uhvatiti promjene druge liste u istom dokumentu.
     * EN: Following one list must not match another list's changes in the same document.
     */
    public function testTaskListFollowsRemainSeparatedInsideOneDocument(): void
    {
        $preferences = new UserPreferenceService($this->database);
        $follows = new FollowService($this->database, $this->targets, $preferences);
        $follows->follow(7, 'task_list', 'list-a', ['document_id' => 'doc-1']);
        $follows->follow(8, 'task_list', 'list-b', ['document_id' => 'doc-1']);

        $matched = $follows->matchingFollows('task_list', 'list-a', 12, 42);

        $this->assertCount(1, $matched);
        $this->assertSame(7, (int)$matched[0]['user_id']);
    }

    /**
     * HR: Potvrđuje zadane postavke i eksplicitni način dnevnog sažetka.
     * EN: Verifies default preferences and an explicit daily-digest mode.
     */
    public function testPersonalPreferencesAreStoredPerUser(): void
    {
        $preferences = new UserPreferenceService($this->database);
        $this->assertSame('off', $preferences->forUser(3)['email_mode']);

        $saved = $preferences->save(3, 'daily', true);
        $this->assertSame('daily', $saved['email_mode']);
        $this->assertTrue($saved['notify_own_changes']);
        $this->assertSame('off', $preferences->forUser(4)['email_mode']);
    }

    /**
     * HR: Komponentni i pojedinačni Workspace backup moraju imati samo ovisnosti
     *     koje doista postoje u njihovu opsegu arhiva.
     * EN: Component and single-Workspace backups must declare only dependencies
     *     that actually exist in their respective archive scope.
     */
    public function testWorkspaceBackupMetadataKeepsScopeDependenciesSeparate(): void
    {
        $identities = new AuthBackupIdentityResolver($this->database, new AuthUserService($this->database));
        $component = new SimbiozaUserWorkspaceBackupProvider(
            $this->database,
            $identities,
            'simbioza-user-workspaces',
            ['workspace', 'calendar'],
            [BackupScope::COMPONENT],
        );
        $workspace = new SimbiozaUserWorkspaceBackupProvider(
            $this->database,
            $identities,
            'simbioza-user-workspace',
            ['workspace-scope', 'calendar-workspace'],
            [BackupScope::WORKSPACE],
        );

        $this->assertSame([BackupScope::COMPONENT], $component->metadata()->scopes);
        $this->assertSame(['workspace', 'calendar'], $component->metadata()->dependencies);
        $this->assertSame([BackupScope::WORKSPACE], $workspace->metadata()->scopes);
        $this->assertSame(['workspace-scope', 'calendar-workspace'], $workspace->metadata()->dependencies);
    }

    /**
     * HR: Potvrđuje prijenos starih pretplata i uklanjanje praćenja nakon odjave.
     * EN: Verifies legacy-subscription import and follow removal after unsubscription.
     */
    public function testCalendarSubscriptionsAreReconciledWithoutASecondFollowControl(): void
    {
        $calendarMigration = $this->resolveCalendarMigrationPath('initial_calendar_schema.php');
        $this->runMigration($calendarMigration);
        $now = '2026-08-18 12:00:00';
        $this->database->table(ModuleCalendar::TABLE_CALENDAR_FOLLOWERS)->insert([
            'calendar_id' => 73,
            'user_id' => 7,
            'is_subscribed' => true,
            'is_visible' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->database->table(ModuleCalendar::TABLE_CALENDAR_FOLLOWERS)->insert([
            'calendar_id' => 74,
            'user_id' => 7,
            'is_subscribed' => false,
            'is_visible' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $preferences = new UserPreferenceService($this->database);
        $follows = new FollowService($this->database, $this->targets, $preferences);
        $synchronizer = new CalendarSubscriptionSynchronizer($this->database, $follows);

        $this->assertSame(1, $synchronizer->syncUser(7));
        $this->assertTrue($follows->isFollowing(7, 'calendar', '73'));
        $this->assertFalse($follows->isFollowing(7, 'calendar', '74'));

        $this->assertTrue($follows->excludeAutomaticFollow(7, 'calendar', '73', 'calendar_subscription'));
        $this->assertTrue($follows->isAutomaticFollowExcluded(7, 'calendar', '73'));
        $this->assertSame(0, $synchronizer->syncUser(7));
        $this->assertFalse($follows->isFollowing(7, 'calendar', '73'));
        $this->assertNotNull($follows->availableTarget(7, 'calendar', '73'));

        $follows->follow(7, 'calendar', '73');
        $this->assertFalse($follows->isAutomaticFollowExcluded(7, 'calendar', '73'));
        $this->assertTrue($follows->isFollowing(7, 'calendar', '73'));

        $this->database->table(ModuleCalendar::TABLE_CALENDAR_FOLLOWERS)
            ->where('calendar_id', '=', 73)
            ->where('user_id', '=', 7)
            ->update(['is_subscribed' => false, 'updated_at' => $now]);

        $this->assertSame(1, $synchronizer->syncCalendar(73));
        $this->assertFalse($follows->isFollowing(7, 'calendar', '73'));
        $this->assertFalse($follows->isAutomaticFollowExcluded(7, 'calendar', '73'));
    }

    /**
     * HR: Promjena ugrađenog sadržaja obavještava pratitelja stranice samo jednom
     *     i vodi ga na ACL-provjerenu stranicu.
     * EN: An embedded-content change notifies a page follower only once and links
     *     to the ACL-checked page.
     */
    public function testEmbeddedCalendarActivityUsesRelatedPageFollow(): void
    {
        $preferences = new UserPreferenceService($this->database);
        $preferences->save(21, 'daily', false);

        $follows = new FollowService($this->database, $this->targets, $preferences);
        $follows->follow(21, 'page', '55', ['document_id' => 'doc-55']);

        $notificationPreferences = new NotificationPreferenceService($this->database);
        $notificationPreferences->saveEmailEnabled(21, true);

        $notifications = new NotificationService(
            $this->database,
            new NotificationEmailBridge($this->emptyContainer(), $notificationPreferences),
            new NotificationVisibilityRegistry(),
        );
        $delivery = new FollowDeliveryService(
            $this->database,
            $follows,
            $this->targets,
            $preferences,
            $notificationPreferences,
            $notifications,
            $this->emptyContainer(),
            new NullLogger(),
        );

        $activity = new FollowActivity(
            eventKey: 'calendar.updated',
            targetType: 'calendar',
            targetId: '73',
            title: 'Calendar changed',
            message: 'Event changed.',
            workspaceId: 12,
            pageId: 55,
            documentId: 'doc-55',
            relatedTitle: 'Calendar changed on followed page',
            relatedMessage: 'An embedded calendar changed.',
            dedupIdentity: 'calendar:900:updated',
        );

        $this->assertSame(1, $delivery->process($activity));
        $this->assertSame(1, $delivery->process($activity));
        $inbox = $notifications->inbox(21);
        $this->assertSame(1, $inbox['total']);
        $this->assertSame('Calendar changed on followed page', $inbox['items'][0]['title'] ?? null);
        $this->assertSame('/target/55', $inbox['items'][0]['link_url'] ?? null);
        $pending = $this->database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)->first();
        $this->assertIsArray($pending);
        $this->assertSame('page', $pending['target_type'] ?? null);
        $this->assertSame('55', $pending['target_id'] ?? null);
        $this->assertSame('/target/55', $pending['link_url'] ?? null);
    }

    /**
     * HR: Potvrđuje jednu dedupliciranu in-app obavijest i ACL skrivanje nakon gubitka prava.
     * EN: Verifies one de-duplicated in-app notification and ACL hiding after access is lost.
     */
    public function testDeliveryDeduplicatesAndNotificationAclFailsClosed(): void
    {
        $preferences = new UserPreferenceService($this->database);
        $follows = new FollowService($this->database, $this->targets, $preferences);
        $follows->follow(11, 'page', '55', ['document_id' => 'doc-55']);

        $notificationPreferences = new NotificationPreferenceService($this->database);
        $visibility = new NotificationVisibilityRegistry();
        $visibility->register(new SimbiozaNotificationVisibilityProvider($this->targets));

        $notifications = new NotificationService(
            $this->database,
            new NotificationEmailBridge($this->emptyContainer(), $notificationPreferences),
            $visibility,
        );
        $delivery = new FollowDeliveryService(
            $this->database,
            $follows,
            $this->targets,
            $preferences,
            $notificationPreferences,
            $notifications,
            $this->emptyContainer(),
            new NullLogger(),
        );
        $activity = new FollowActivity(
            eventKey: 'workspace.publication_changed',
            targetType: 'page',
            targetId: '55',
            title: 'Changed page',
            message: 'A new version was published.',
            workspaceId: 12,
            pageId: 55,
            documentId: 'doc-55',
        );

        $this->assertSame(1, $delivery->process($activity));
        $this->assertSame(1, $delivery->process($activity));
        $this->assertSame(1, $notifications->inbox(11)['total']);

        $this->setTargetAccess(false);
        $this->assertSame(0, $notifications->inbox(11)['total']);
        $this->assertSame(0, $notifications->unreadCount(11));
    }

    /**
     * HR: Neuspjela e-mail predaja ostavlja dnevni sažetak spremnim za ponovni pokušaj.
     * EN: A failed e-mail handoff leaves the daily digest ready for retry.
     */
    public function testFailedDailyDigestIsNotMarkedAsDelivered(): void
    {
        $preferences = new UserPreferenceService($this->database);
        $preferences->save(12, 'daily', false);

        $follows = new FollowService($this->database, $this->targets, $preferences);
        $follows->follow(12, 'page', '72', ['document_id' => 'doc-72']);

        $notificationPreferences = new NotificationPreferenceService($this->database);
        $notificationPreferences->saveEmailEnabled(12, true);

        $notifications = new NotificationService(
            $this->database,
            new NotificationEmailBridge($this->emptyContainer(), $notificationPreferences),
            new NotificationVisibilityRegistry(),
        );
        $delivery = new FollowDeliveryService(
            $this->database,
            $follows,
            $this->targets,
            $preferences,
            $notificationPreferences,
            $notifications,
            $this->emptyContainer(),
            new NullLogger(),
        );

        $this->assertSame(1, $delivery->process(new FollowActivity(
            eventKey: 'workspace.publication_changed',
            targetType: 'page',
            targetId: '72',
            title: 'Changed page',
            message: 'A new version was published.',
            workspaceId: 12,
            pageId: 72,
            documentId: 'doc-72',
        )));
        $this->database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)
            ->update(['deliver_after' => '2000-01-01 00:00:00']);

        $this->assertSame(0, $delivery->dispatchDueDigests());
        $pending = $this->database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)->first();
        $this->assertIsArray($pending);
        $this->assertNull($pending['delivered_at'] ?? null);
    }

    /** HR: Izvršava jednu migraciju kao u aplikaciji. EN: Runs one migration as the application does. */
    private function runMigration(string $path): void
    {
        $migration = require $path;
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($this->database);
    }

    /** HR: Vraća putanju migracije ovisnog modula neovisno o načinu instalacije. EN: Returns migration path of a dependency module regardless of install source. */
    private function resolveNotificationMigrationPath(string $file): string
    {
        return $this->resolveModuleMigrationPath(NotificationPreferenceService::class, $file);
    }

    /** HR: Vraća putanju migracije kalendarskog modula neovisno o načinu instalacije. EN: Returns migration path for Calendar module regardless of installation source. */
    private function resolveCalendarMigrationPath(string $file): string
    {
        return $this->resolveModuleMigrationPath(ModuleCalendar::class, $file);
    }

    /** HR: Pronalaženje migracije u direktoriju ovisnog modula kroz putanju klase. EN: Finds migration by walking up class file path. */
    private function resolveModuleMigrationPath(string $className, string $file): string
    {
        $basePath = (new ReflectionClass($className))->getFileName();

        for ($i = 0; $i < 6; ++$i) {
            $basePath = dirname($basePath);
            $path = $basePath . '/resources/migrations/' . $file;

            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException(sprintf('Module migration not found: %s', $file));
    }

    /** HR: Mijenja kontroliranu ACL odluku testnog resolvera. EN: Changes the controlled ACL decision of the test resolver. */
    private function setTargetAccess(bool $accessible): void
    {
        (new ReflectionProperty($this->targets, 'accessible'))->setValue($this->targets, $accessible);
    }

    /** HR: Vraća container bez opcionalnih kanala. EN: Returns a container without optional channels. */
    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            /** {@inheritDoc} */
            public function get(string $id): never
            {
                throw new \RuntimeException('Service is not available: ' . $id);
            }

            /** {@inheritDoc} */
            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
