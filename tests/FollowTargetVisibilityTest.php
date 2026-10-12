<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Tests;

use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleCalendar\Service\CalendarManager;
use AaiEduHr\HeartPhrameModuleCalendar\Service\CalendarManagerInterface;
use AaiEduHr\HeartPhrameModuleCalendar\Service\CalendarRepository;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\QueryExecuted;
use AaiEduHr\SimbiozaModuleUser\Notification\SimbiozaNotificationVisibilityProvider;
use AaiEduHr\SimbiozaModuleUser\Service\FollowTargetService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

#[CoversClass(FollowTargetService::class)]
#[CoversClass(SimbiozaNotificationVisibilityProvider::class)]
final class FollowTargetVisibilityTest extends TestCase
{
    private Database $database;

    private WorkspaceRepository $workspaces;

    private CalendarRepository $calendars;

    private Config $config;

    private \PHPUnit\Framework\MockObject\MockObject $authn;

    private int $adminId;

    private int $userId;

    /**
     * EN: Uses real dependent-module schemas and ACL services, never a live installation.
     *
     * HR: Koristi stvarne sheme ovisnih modula i ACL servise, nikad stvarnu instalaciju.
     */
    protected function setUp(): void
    {
        $helper = new Helper();
        $this->config = new Config($helper, ['database' => ['connections' => ['default' =>
            ['driver' => 'sqlite', 'database' => ':memory:']]]]);
        $this->database = new Database($this->config, $helper);
        foreach (
            [AuthUserService::class => 'initial_auth_schema.php',
            WorkspaceRepository::class => 'initial_workspace_schema.php',
            CalendarRepository::class => 'initial_calendar_schema.php'] as $class => $filename
        ) {
            $file = (new ReflectionClass($class))->getFileName();
            if (!is_string($file)) {
                throw new RuntimeException('The dependency source must be available.');
            }

            $migration = require dirname($file, 3) . '/resources/migrations/' . $filename;
            $migration->up($this->database);
        }

        $users = new AuthUserService($this->database);
        $admin = $users->createInitialAdministrator(
            'batch-admin',
            'Admin',
            'Test',
            'Admin',
            'batch-admin@example.invalid',
            'FixturePassword2026!',
        );
        $user = $users->createLocalUser(
            'batch-reader',
            'Reader',
            'batch-reader@example.invalid',
            'FixturePassword2026!',
        );
        $this->adminId = (int)$admin['id'];
        $this->userId = (int)$user['id'];
        $this->authn = $this->createMock(AuthnHandlerInterface::class);
        $this->workspaces = new WorkspaceRepository($this->database);
        $this->calendars = new CalendarRepository($this->database, $this->authn);
    }

    /**
     * EN: Distinct targets across 100 Workspaces must not load the same user or ACL per row.
     *     The resolver starts with a fresh access/repository graph for every measurement.
     *
     * HR: Različiti ciljevi kroz 100 područja ne učitavaju istog korisnika ni ACL po retku.
     *     Resolver za svako mjerenje počinje svježim skupom ACL/repozitorijskih servisa.
     */
    public function testDistinctTargetsHaveBoundedColdQueryBudget(): void
    {
        $targets = [];
        foreach (range(1, 100) as $index) {
            $workspace = $this->workspaces->saveWorkspace(['name' => 'Batch ' . $index,
                'slug' => 'batch-' . $index, 'visibility' => 'public'], $this->adminId);
            $parent = $this->workspaces->saveNode(
                (int)$workspace['id'],
                ['title' => 'Parent', 'node_type' => 'separator'],
                $this->adminId,
            );
            $page = $this->workspaces->saveNode((int)$workspace['id'], ['title' => 'Page', 'node_type' => 'document',
                'document_key' => 'batch-' . $index, 'parent_id' => $parent['id']], $this->adminId);
            $targets[] = ['type' => 'page', 'id' => (string)$page['id']];
        }

        foreach ([1, 100] as $size) {
            $resolver = $this->resolver();
            $queries = [];
            $this->database->listen(static function (QueryExecuted $event) use (&$queries): void {
                $queries[] = $event->sql;
            });
            $this->assertSame(
                array_fill(0, $size, true),
                $resolver->canViewTargets($this->userId, array_slice($targets, 0, $size)),
            );
            $this->database->forgetQueryListeners();
            $this->assertLessThanOrEqual(10, count($queries));
            $this->assertCount(1, array_filter(
                $queries,
                static fn(string $sql): bool => str_contains($sql, 'FROM "auth_users"'),
            ));
        }
    }

    /**
     * EN: Calendar/task-list targets also require the destination page; direct grants,
     *     user deactivation and same-service ACL changes must never leak old decisions.
     *
     * HR: Kalendar/lista zadataka zahtijevaju i ciljnu stranicu; izravna prava,
     *     deaktivacija korisnika i ACL promjene ne smiju otkriti stare odluke.
     */
    public function testMixedTargetsAndEmbeddedPageAclRemainFresh(): void
    {
        $workspace = $this->workspaces->saveWorkspace(['name' => 'Restricted', 'slug' => 'restricted',
            'visibility' => 'restricted'], $this->adminId);
        $wid = (int)$workspace['id'];
        $page = $this->workspaces->saveNode($wid, ['title' => 'Granted page', 'node_type' => 'document',
            'document_key' => 'granted-page'], $this->adminId);
        $pid = (int)$page['id'];
        $this->workspaces->replaceNodeDirectPermissions($wid, $pid, [$this->userId => ['can_view' => true]]);
        $calendar = $this->calendars->saveCalendar(['calendar_type' => 'team', 'name' => 'Public calendar',
            'is_enabled' => true, 'is_public_read' => true], ['id' => $this->adminId, 'is_admin' => true]);
        $cid = (string)$calendar['id'];
        $targets = [['type' => 'workspace', 'id' => (string)$wid], ['type' => 'page', 'id' => (string)$pid],
            ['type' => 'task_list', 'id' => 'tasks', 'document_id' => 'granted-page'],
            ['type' => 'calendar', 'id' => $cid],
            ['type' => 'page', 'id' => '999999'], ['type' => 'task_list', 'id' => 'tasks', 'document_id' => 'missing'],
            ['type' => 'unknown', 'id' => '1']];
        $resolver = $this->resolver();
        $this->assertSame(
            [false, true, true, true, false, false, false],
            $resolver->canViewTargets($this->userId, $targets),
        );
        $withoutCalendar = $this->resolver(false);
        $this->assertSame([false], $withoutCalendar->canViewTargets($this->userId, [$targets[3]]));
        $provider = new SimbiozaNotificationVisibilityProvider($resolver);
        $rows = [['source_module' => 'simbioza-user', 'source_id' => $cid, 'data' =>
            ['target_type' => 'calendar', 'target_id' => $cid, 'page_id' => $pid]],
            ['source_module' => 'simbioza-user', 'source_id' => 'tasks', 'data' =>
            ['target_type' => 'task_list', 'target_id' => 'tasks',
                'document_id' => 'granted-page', 'page_id' => $pid]]];
        $this->assertSame([true, true], $provider->canViewBatch($this->userId, $rows));
        $this->workspaces->replaceNodeDirectPermissions($wid, $pid, []);
        $this->assertSame([false, false], $provider->canViewBatch($this->userId, $rows));
        $this->assertSame(
            [false, false, false, true, false, false, false],
            $resolver->canViewTargets($this->userId, $targets),
        );
        $this->database->table('auth_users')->where('id', '=', $this->userId)->update(['is_active' => false]);
        $this->assertSame(array_fill(0, 7, false), $resolver->canViewTargets($this->userId, $targets));
        $this->assertSame(array_fill(0, 7, false), $resolver->canViewTargets(999999, $targets));
        $this->assertSame([], $resolver->canViewTargets($this->userId, []));
    }

    /**
     * EN: Builds a fresh real ACL resolver with an optional real Calendar manager.
     *
     * HR: Stvara svježi stvarni ACL resolver s opcionalnim stvarnim Calendar managerom.
     */
    private function resolver(bool $calendarEnabled = true): FollowTargetService
    {
        $repository = new WorkspaceRepository($this->database);
        $file = (new ReflectionClass(WorkspaceConfig::class))->getFileName();
        if (!is_string($file)) {
            throw new RuntimeException('Workspace configuration source unavailable.');
        }

        $config = new WorkspaceConfig($this->config, dirname($file, 3));
        $manager = $calendarEnabled ? new CalendarManager($this->calendars) : null;
        $container = new class ($manager) implements ContainerInterface {
            public function __construct(private readonly ?CalendarManager $manager)
            {
            }

            public function get(string $id): mixed
            {
                if (
                    $id === CalendarManagerInterface::class
                    && $this->manager instanceof CalendarManager
                ) {
                    return $this->manager;
                }

                throw new RuntimeException('Optional module unavailable.');
            }

            public function has(string $id): bool
            {
                return $id === CalendarManagerInterface::class
                    && $this->manager instanceof CalendarManager;
            }
        };
        return new FollowTargetService(
            $this->database,
            $repository,
            new WorkspaceAccessService($repository, $this->authn, $config, new WorkspaceWorkflowService($repository)),
            $config,
            $this->createMock(TranslatorInterface::class),
            $this->createMock(UrlGenerator::class),
            $container,
        );
    }
}
