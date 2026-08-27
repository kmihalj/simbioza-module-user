<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Tests;

use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspacePresentationProvider;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Localization\TranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(PersonalWorkspaceService::class)]
#[CoversClass(PersonalWorkspacePresentationProvider::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleUser\Event\PersonalWorkspaceChanged::class)]
final class PersonalWorkspaceServiceTest extends TestCase
{
    private Database $database;

    private PersonalWorkspaceService $service;

    /** HR: Priprema stvarne prenosive sheme ovisnih modula. EN: Prepares real portable schemas of dependent modules. */
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
        $this->runMigration($this->moduleMigration(AuthUserService::class, 'initial_auth_schema.php'));
        $this->runMigration($this->moduleMigration(WorkspaceRepository::class, 'initial_workspace_schema.php'));
        $this->runMigration(dirname(__DIR__) . '/resources/migrations/initial_simbioza_user_schema.php');
        $users = new AuthUserService($this->database);
        $this->service = new PersonalWorkspaceService(
            $this->database,
            new WorkspaceRepository($this->database),
            $users,
        );
    }

    /** HR: Prva prijava izrađuje jedno ograničeno područje sa svim pravima i ponovljena je idempotentna. EN: First sign-in creates one restricted space with every permission and repeated calls are idempotent. */
    public function testFirstLoginCreatesOneRestrictedPersonalWorkspace(): void
    {
        $userId = $this->insertUser('ana.horvat');

        $created = $this->service->ensureAfterLogin($userId);
        $again = $this->service->ensureAfterLogin($userId);

        $this->assertIsArray($created);
        $this->assertIsArray($again);
        $this->assertSame($created['workspace_id'], $again['workspace_id']);
        $workspace = $created['workspace'];
        $this->assertSame('Područje od: ana.horvat', $workspace['name']);
        $this->assertSame('restricted', $workspace['visibility']);
        $this->assertStringStartsWith('osobno-ana-horvat', (string)$workspace['slug']);
        $this->assertSame(1, $this->tableCount(ModuleWorkspace::TABLE_WORKSPACES));
        $acl = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)
            ->where('workspace_id', '=', (int)$workspace['id'])
            ->get();
        $this->assertCount(1, $acl);
        $this->assertSame($userId, (int)$acl[0]['subject_id']);
        foreach (['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'] as $permission) {
            $this->assertTrue((bool)$acl[0][$permission], $permission);
        }

        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)
            ->where('workspace_id', '=', (int)$workspace['id'])
            ->delete();
        $this->assertSame(0, $this->tableCount(ModuleWorkspace::TABLE_WORKSPACE_ACL));
        $repaired = $this->service->ensureAfterLogin($userId);
        $this->assertIsArray($repaired);
        $this->assertSame(1, $this->tableCount(ModuleWorkspace::TABLE_WORKSPACE_ACL));
    }

    /** HR: Globalno i korisničko isključenje zaustavljaju samo automatsku izradu. EN: Global and per-user exclusions stop automatic creation only. */
    public function testAdministratorPoliciesControlAutomaticCreation(): void
    {
        $first = $this->insertUser('disabled.global');
        $this->service->setAutomaticCreationEnabled(false);
        $this->assertNull($this->service->ensureAfterLogin($first));

        $second = $this->insertUser('disabled.user');
        $this->service->setAutomaticCreationEnabled(true);
        $this->service->setAutomaticCreationForUser($second, false, $first);
        $this->assertNull($this->service->ensureAfterLogin($second));

        $manuallyCreated = $this->service->ensureForUser($second, $first, false);
        $this->assertIsArray($manuallyCreated);
        $this->assertFalse((bool)$manuallyCreated['created_automatically']);
        $this->assertSame($first, (int)$manuallyCreated['workspace']['created_by_user_id']);
        $acl = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_ACL)
            ->where('workspace_id', '=', (int)$manuallyCreated['workspace_id'])
            ->get();
        $this->assertCount(1, $acl);
        $this->assertSame($second, (int)$acl[0]['subject_id']);
        $this->assertTrue((bool)$acl[0]['can_manage']);
    }

    /** HR: Skupna radnja preskače deaktivirane i izričito isključene korisnike. EN: Batch provisioning skips inactive and explicitly excluded users. */
    public function testExistingUserProvisioningReportsEveryOutcome(): void
    {
        $admin = $this->insertUser('admin');
        $enabled = $this->insertUser('enabled');
        $disabled = $this->insertUser('disabled');
        $this->insertUser('inactive', false);
        $this->service->ensureForUser($admin, $admin, false);
        $this->service->setAutomaticCreationForUser($disabled, false, $admin);

        $result = $this->service->provisionExistingUsers($admin);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['existing']);
        $this->assertSame(1, $result['disabled']);
        $this->assertSame(0, $result['failed']);
        $this->assertIsArray($this->service->forUser($enabled));
        $this->assertNull($this->service->forUser($disabled));
    }

    /** HR: Generirani naziv i opis prate jezik sučelja bez promjene baze. EN: Generated name and description follow the UI locale without changing the database. */
    public function testPresentationLocalizesExistingPersonalWorkspace(): void
    {
        $userId = $this->insertUser('ana.horvat');
        $created = $this->service->ensureForUser($userId, $userId, false);
        $this->assertIsArray($created);
        $workspace = $created['workspace'];
        $translator = new class implements TranslatorInterface {
            public function trans(string $key, array $replace = [], ?string $locale = null): string
            {
                if ($locale === 'en') {
                    return match ($key) {
                        'Područje od: %s' => 'Workspace of: %s',
                        'Osobno područje korisnika %s.' => 'Personal Workspace of user %s.',
                        default => $key,
                    };
                }

                return $key;
            }

            public function getLocale(): string
            {
                return 'hr';
            }

            public function setLocale(string $locale): void
            {
            }
        };
        $provider = new PersonalWorkspacePresentationProvider($this->service, $translator);

        $english = $provider->present([$workspace], 'en')[0];
        $croatian = $provider->present([$workspace], 'hr')[0];

        $this->assertSame('Workspace of: ana.horvat', $english['name']);
        $this->assertSame('Personal Workspace of user ana.horvat.', $english['description']);
        $this->assertSame('Područje od: ana.horvat', $croatian['name']);
        $this->assertSame('Osobno područje korisnika ana.horvat.', $croatian['description']);
        $stored = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
            ->where('id', '=', (int)$workspace['id'])
            ->first();
        $this->assertSame('Područje od: ana.horvat', $stored['name'] ?? null);

        $renamed = $provider->present([[
            ...$workspace,
            'name' => 'Ana private notes',
            'description' => 'My own description.',
        ]], 'hr')[0];
        $this->assertSame('Ana private notes', $renamed['name']);
        $this->assertSame('My own description.', $renamed['description']);
    }

    /** HR: Sprema minimalan Auth korisnički red. EN: Stores a minimal Auth user row. */
    private function insertUser(string $login, bool $active = true): int
    {
        $now = '2026-08-20 15:00:00';
        $this->database->table(ModuleAuth::TABLE_AUTH_USERS)->insert([
            'login_identifier' => $login,
            'password_hash' => null,
            'is_admin' => false,
            'is_active' => $active,
            'auth_source' => 'local',
            'last_login_at' => null,
            'must_change_password' => false,
            'force_local_password_reset_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->database->lastInsertId();
    }

    /** HR: Broji retke jedne tablice. EN: Counts rows in one table. */
    private function tableCount(string $table): int
    {
        $row = $this->database->table($table)->select(['COUNT(*) AS total'])->first();

        return is_array($row) ? (int)($row['total'] ?? 0) : 0;
    }

    /** HR: Izvršava prenosivu migraciju. EN: Runs a portable migration. */
    private function runMigration(string $path): void
    {
        $migration = require $path;
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($this->database);
    }

    /** HR: Pronalazi migraciju ovisnosti u vendor ili sibling instalaciji. EN: Finds a dependency migration in vendor or sibling installation. */
    private function moduleMigration(string $className, string $file): string
    {
        $path = (new ReflectionClass($className))->getFileName();
        for ($level = 0; $level < 7; ++$level) {
            $path = dirname($path);
            $candidate = $path . '/resources/migrations/' . $file;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Module migration not found: ' . $file);
    }
}
