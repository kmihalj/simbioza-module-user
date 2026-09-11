<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Tests;

use AaiEduHr\HeartPhrameModuleAuth\Event\UserAuthenticated;
use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthGroupService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\SimbiozaModuleUser\Listener\ActivateAdministratorAfterLocalLogin;
use AaiEduHr\SimbiozaModuleUser\Security\SimbiozaAdminContextDecorator;
use AaiEduHr\SimbiozaModuleUser\Security\SimbiozaAdminElevationService;
use AaiEduHr\SimbiozaModuleUser\Tests\Support\InMemorySession;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SimbiozaAdminElevationService::class)]
#[UsesClass(AuthGroupService::class)]
#[UsesClass(AuthUserService::class)]
#[UsesClass(ActivateAdministratorAfterLocalLogin::class)]
#[UsesClass(SimbiozaAdminContextDecorator::class)]
final class SimbiozaAdminElevationServiceTest extends TestCase
{
    private Database $database;

    private AuthGroupService $groups;

    private SimbiozaAdminElevationService $service;

    private InMemorySession $session;

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
        $migrationPath = dirname((new \ReflectionClass(AuthUserService::class))->getFileName())
            . '/../../resources/migrations/initial_auth_schema.php';
        $migration = require $migrationPath;
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($this->database);

        $this->groups = new AuthGroupService($this->database);
        $users = new AuthUserService($this->database, null, $this->groups);
        $this->session = new InMemorySession();
        $this->session->start();

        $this->service = new SimbiozaAdminElevationService($this->session, $users);
    }

    /** HR: Admin se bez potvrde prikazuje bez bypassa i admin grupe, a potvrda ih vraća. EN: An unconfirmed admin loses bypass and admin group until confirmation restores them. */
    public function testEffectiveRightsFollowElevationState(): void
    {
        $userId = $this->insertUser('admin', true, 'tajna-lozinka');
        $ordinaryGroup = $this->groups->createGroupForApi('Urednici');
        $ordinaryGroupId = (int)$ordinaryGroup['id'];
        $this->groups->saveManualGroupsForUser($userId, [$ordinaryGroupId], true);
        $this->groups->syncAdministratorMembership($userId, true);

        $raw = $this->service->user($userId);
        $this->assertIsArray($raw);
        $this->assertContains('administrator', $raw['group_keys']);

        $ordinary = $this->service->effectiveUser($raw);
        $this->assertIsArray($ordinary);
        $this->assertFalse($ordinary['is_admin']);
        $this->assertFalse($ordinary['admin_elevated']);
        $this->assertNotContains('administrator', $ordinary['group_keys']);
        $this->assertSame([$ordinaryGroupId], $ordinary['group_ids']);
        $this->assertContains('urednici', $ordinary['group_keys']);

        $this->assertSame(
            SimbiozaAdminElevationService::STATUS_INVALID_PASSWORD,
            $this->service->activate($userId, 'pogrešna'),
        );
        $this->assertSame(
            SimbiozaAdminElevationService::STATUS_ACTIVATED,
            $this->service->activate($userId, 'tajna-lozinka'),
        );
        $elevated = $this->service->effectiveUser($raw);
        $this->assertIsArray($elevated);
        $this->assertTrue($elevated['is_admin']);
        $this->assertTrue($elevated['admin_elevated']);
        $this->assertContains('administrator', $elevated['group_keys']);
        $this->assertNotSame([], $elevated['group_ids']);

        $this->service->deactivate($userId);
        $ordinaryAgain = $this->service->effectiveUser($raw);
        $this->assertIsArray($ordinaryAgain);
        $this->assertFalse($ordinaryAgain['is_admin']);
        $this->assertGreaterThanOrEqual(2, $this->session->regenerations);
    }

    /** HR: Bez lokalne lozinke nema stavke ni aktivacije, a obični korisnik nikada nije podoban. EN: Missing local password hides elevation, and ordinary users are never eligible. */
    public function testEligibilityRequiresAdministratorAndLocalPassword(): void
    {
        $adminWithoutPassword = $this->insertUser('admin.no-password', true, null);
        $ordinary = $this->insertUser('ordinary', false, 'ordinary-password');

        $this->assertFalse($this->service->canElevate($adminWithoutPassword));
        $this->assertSame(
            SimbiozaAdminElevationService::STATUS_MISSING_PASSWORD,
            $this->service->activate($adminWithoutPassword, 'anything'),
        );
        $this->assertFalse($this->service->canElevate($ordinary));
        $this->assertSame(
            SimbiozaAdminElevationService::STATUS_NOT_ADMINISTRATOR,
            $this->service->activate($ordinary, 'ordinary-password'),
        );
    }

    /** HR: Potvrđena lokalna prijava odmah elevatira samo gotov administratorski račun. EN: A verified local sign-in immediately elevates only a ready administrator account. */
    public function testVerifiedLocalLoginActivatesEligibleAdministrator(): void
    {
        $adminId = $this->insertUser('local-admin', true, 'admin-password');
        $ordinaryId = $this->insertUser('local-user', false, 'user-password');
        $temporaryAdminId = $this->insertUser('temporary-admin', true, 'temporary-password', true);
        $this->groups->syncAdministratorMembership($adminId, true);
        $this->groups->syncAdministratorMembership($temporaryAdminId, true);

        $this->assertTrue($this->service->activateAfterVerifiedLocalLogin($adminId));
        $this->assertTrue($this->service->isElevated($adminId));

        $this->service->clear();
        $this->assertFalse($this->service->activateAfterVerifiedLocalLogin($ordinaryId));
        $this->assertFalse($this->service->isElevated($ordinaryId));

        $this->assertFalse($this->service->activateAfterVerifiedLocalLogin($temporaryAdminId));
        $this->assertFalse($this->service->isElevated($temporaryAdminId));
    }

    /** HR: Listener prihvaća samo Authovu potvrdu lokalne prijave. EN: The listener accepts only Auth's verified local sign-in event. */
    public function testLoginListenerDoesNotElevateExternalSignIn(): void
    {
        $adminId = $this->insertUser('provider-admin', true, 'admin-password');
        $this->groups->syncAdministratorMembership($adminId, true);
        $listener = new ActivateAdministratorAfterLocalLogin($this->service, new NullLogger());

        $listener(new UserAuthenticated($adminId, 'saml'));
        $this->assertFalse($this->service->isElevated($adminId));

        $listener(new UserAuthenticated($adminId, 'local'));
        $this->assertTrue($this->service->isElevated($adminId));
    }

    /** HR: Pet pogrešnih potvrda zaključava i naknadno ispravnu lozinku. EN: Five invalid confirmations also lock a subsequent correct password. */
    public function testRepeatedFailuresTemporarilyLockElevation(): void
    {
        $userId = $this->insertUser('locked-admin', true, 'correct-password');
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->assertSame(
                SimbiozaAdminElevationService::STATUS_INVALID_PASSWORD,
                $this->service->activate($userId, 'wrong-password'),
            );
        }

        $this->assertSame(
            SimbiozaAdminElevationService::STATUS_LOCKED,
            $this->service->activate($userId, 'correct-password'),
        );
        $this->assertFalse($this->service->isElevated($userId));
    }

    /** HR: Auth dekorator primjenjuje efektivna prava i resetira ih pri prijavi ili odjavi. EN: The Auth decorator applies effective rights and resets them on sign-in or sign-out. */
    public function testAuthDecoratorResetsElevation(): void
    {
        $userId = $this->insertUser('decorated-admin', true, 'correct-password');
        $this->groups->syncAdministratorMembership($userId, true);
        $raw = $this->service->user($userId);
        $this->assertIsArray($raw);
        $this->assertSame(
            SimbiozaAdminElevationService::STATUS_ACTIVATED,
            $this->service->activate($userId, 'correct-password'),
        );

        $decorator = new SimbiozaAdminContextDecorator($this->service);
        $this->assertTrue($decorator->decorate($raw)['is_admin']);
        $decorator->reset();
        $this->assertFalse($decorator->decorate($raw)['is_admin']);
    }

    private function insertUser(
        string $login,
        bool $admin,
        ?string $password,
        bool $mustChangePassword = false,
    ): int {
        $now = '2026-09-10 12:00:00';
        $this->database->table(ModuleAuth::TABLE_AUTH_USERS)->insert([
            'login_identifier' => $login,
            'password_hash' => $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null,
            'is_admin' => $admin,
            'is_active' => true,
            'auth_source' => 'local',
            'last_login_at' => null,
            'must_change_password' => $mustChangePassword,
            'force_local_password_reset_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->database->lastInsertId();
    }
}
