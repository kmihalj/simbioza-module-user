<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Tests;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiScopeRegistry;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthApiKeyService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationPreferenceService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\SimbiozaModuleUser\Api\SimbiozaUserResourceController;
use AaiEduHr\SimbiozaModuleUser\Contract\FollowTargetResolverInterface;
use AaiEduHr\SimbiozaModuleUser\Event\PersonalWorkspaceChanged;
use AaiEduHr\SimbiozaModuleUser\Service\FollowService;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use AaiEduHr\SimbiozaModuleUser\Service\UserPreferenceService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspacePresentationRegistry;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Http\Request;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Http\StreamFactory;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\View\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

#[CoversClass(SimbiozaUserResourceController::class)]
#[UsesClass(ApiResponseFactory::class)]
#[UsesClass(ApiScopeRegistry::class)]
#[UsesClass(AuthApiKeyService::class)]
#[UsesClass(PersonalWorkspaceService::class)]
#[UsesClass(PersonalWorkspaceChanged::class)]
#[UsesClass(FollowService::class)]
#[UsesClass(UserPreferenceService::class)]
final class PersonalWorkspaceApiTest extends TestCase
{
    private Database $database;

    private AuthApiKeyService $keys;

    private ApiScopeRegistry $scopes;

    private PersonalWorkspaceService $personalWorkspaces;

    private SimbiozaUserResourceController $controller;

    private string $temporaryDirectory;

    private string|false $previousConfigPath;

    private string|false $previousAppPath;

    private string|false $previousOverridePaths;

    /**
     * EN: Uses real key issuance, advertised descriptors and portable in-memory schemas.
     *
     * HR: Koristi stvarno izdavanje ključeva, oglašene descriptore i prenosive sheme u memoriji.
     */
    protected function setUp(): void
    {
        $this->previousConfigPath = getenv('HPH_CONFIG_PATH');
        $this->previousAppPath = getenv('HPH_APP_PATH');
        $this->previousOverridePaths = getenv('HPH_CONFIG_OVERRIDE_PATHS');
        $this->temporaryDirectory = sys_get_temp_dir() . '/simbioza-user-api-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->temporaryDirectory, 0700));
        file_put_contents(
            $this->temporaryDirectory . '/app.php',
            "<?php return ['name' => 'Isolated API regression'];",
        );
        putenv('HPH_CONFIG_PATH=' . $this->temporaryDirectory);
        putenv('HPH_APP_PATH=' . $this->temporaryDirectory);
        putenv('HPH_CONFIG_OVERRIDE_PATHS');
        $helper = new Helper();
        $config = new Config($helper, ['database' => ['connections' => ['default' =>
            ['driver' => 'sqlite', 'database' => ':memory:']]]], $this->temporaryDirectory);
        $this->database = new Database($config, $helper);
        $authRoot = $this->moduleRoot(AuthUserService::class);
        $workspaceRoot = $this->moduleRoot(WorkspaceRepository::class);
        foreach (
            [$authRoot . '/resources/migrations/initial_auth_schema.php',
            $workspaceRoot . '/resources/migrations/initial_workspace_schema.php',
            __DIR__ . '/../resources/migrations/initial_simbioza_user_schema.php'] as $path
        ) {
            $migration = require $path;
            $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
            $migration->up($this->database);
        }

        $users = new AuthUserService($this->database);
        $workspaces = new WorkspaceRepository($this->database);
        $this->keys = new AuthApiKeyService($this->database, $users);
        $this->personalWorkspaces = new PersonalWorkspaceService($this->database, $workspaces, $users);
        $paths = ['aaieduhr/simbioza-module-workspace' => $workspaceRoot,
            'aaieduhr/simbioza-module-user' => dirname(__DIR__)];
        $composer = $this->createMock(ComposerBridge::class);
        $composer->method('isInstalled')->willReturnCallback(static fn(string $name): bool => isset($paths[$name]));
        $composer->method('getInstallPath')->willReturnCallback(
            static fn(string $name): ?string => $paths[$name] ?? null,
        );
        $catalogConfig = $this->createStub(ConfigInterface::class);
        $catalogConfig->method('getAsArrayWithValuesAsNonEmptyStrings')->willReturn(array_keys($paths));
        $this->scopes = new ApiScopeRegistry($composer, $catalogConfig);
        $preferences = new UserPreferenceService($this->database);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');
        $this->controller = new SimbiozaUserResourceController(
            new ApiResponseFactory(new ResponseFactory(new StreamFactory(), $this->createStub(View::class))),
            new FollowService($this->database, $this->createStub(FollowTargetResolverInterface::class), $preferences),
            $preferences,
            new NotificationPreferenceService($this->database),
            $this->personalWorkspaces,
            new WorkspacePresentationRegistry($translator, $workspaces, new WorkspaceConfig($config, $workspaceRoot)),
        );
    }

    /**
     * EN: Restores process configuration and removes the isolated empty settings directory.
     *
     * HR: Vraća procesnu konfiguraciju i uklanja izdvojeni prazni direktorij postavki.
     */
    protected function tearDown(): void
    {
        putenv($this->previousConfigPath === false
            ? 'HPH_CONFIG_PATH' : 'HPH_CONFIG_PATH=' . $this->previousConfigPath);
        putenv($this->previousAppPath === false ? 'HPH_APP_PATH' : 'HPH_APP_PATH=' . $this->previousAppPath);
        putenv($this->previousOverridePaths === false
            ? 'HPH_CONFIG_OVERRIDE_PATHS' : 'HPH_CONFIG_OVERRIDE_PATHS=' . $this->previousOverridePaths);
        if (is_dir($this->temporaryDirectory)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temporaryDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->temporaryDirectory);
        }
    }

    /**
     * EN: A normally issued read key resolves only its owner, ignoring attempted owner selection.
     *
     * HR: Redovno izdan ključ za čitanje razrješava samo vlasnika, ne pokušaj odabira drugog korisnika.
     */
    public function testAdvertisedReadScopeReturnsOnlyKeyOwnersPrivateWorkspace(): void
    {
        $first = $this->insertUser('first.owner');
        $second = $this->insertUser('second.owner');
        $own = $this->personalWorkspaces->ensureForUser($first, $first, false);
        $other = $this->personalWorkspaces->ensureForUser($second, $second, false);
        $request = $this->request($first, ['workspace:read'])->withQueryParams(['user_id' => $second]);

        $response = $this->controller->getPersonalWorkspace($request);
        $payload = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['data']['exists']);
        $this->assertSame((int)$own['workspace_id'], $payload['data']['workspace']['id']);
        $this->assertSame('restricted', $payload['data']['workspace']['visibility']);
        $this->assertNotSame((int)$other['workspace_id'], $payload['data']['workspace']['id']);
        $this->assertStringNotContainsString((string)$other['workspace']['slug'], (string)$response->getBody());
        $this->assertStringNotContainsString('password', (string)$response->getBody());
    }

    /**
     * EN: Reading never creates a missing personal Workspace or widens unrelated follow permissions.
     *
     * HR: Čitanje ne stvara nedostajuće osobno područje niti proširuje nepovezana prava praćenja.
     */
    public function testReadKeyDoesNotCreateWorkspaceOrAllowFollowOperations(): void
    {
        $userId = $this->insertUser('read.only');
        $request = $this->request($userId, ['workspace:read']);
        $response = $this->controller->getPersonalWorkspace($request);
        $payload = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($payload['data']['exists']);
        $this->assertNull($payload['data']['workspace']);
        $this->assertNull($this->personalWorkspaces->forUser($userId));
        foreach (['listFollows', 'createFollow', 'deleteFollow', 'getPreferences', 'updatePreferences'] as $action) {
            $denied = $this->controller->{$action}($request);
            $problem = json_decode((string)$denied->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(403, $denied->getStatusCode(), $action);
            $this->assertSame('insufficient_scope', $problem['code'], $action);
        }
    }

    /**
     * EN: An unrelated valid scope cannot read the key owner's private Workspace.
     *
     * HR: Nepovezani valjani scope ne dopušta čitanje privatnog područja vlasnika ključa.
     */
    public function testFollowReadScopeCannotReadPersonalWorkspace(): void
    {
        $userId = $this->insertUser('follows.only');
        $this->personalWorkspaces->ensureForUser($userId, $userId, false);
        $response = $this->controller->getPersonalWorkspace($this->request($userId, ['follows:read']));
        $payload = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('insufficient_scope', $payload['code']);
        $this->assertStringContainsString('workspace:read', (string)$payload['detail']);
        $this->assertArrayNotHasKey('workspace', $payload);
    }

    /**
     * EN: The obsolete plural scope remains unavailable rather than becoming a new capability.
     *
     * HR: Stari scope u množini ostaje nedostupan umjesto da postane nova ovlast.
     */
    public function testTypoIsNotAddedToAllowedScopeCatalog(): void
    {
        $this->expectException(RuntimeException::class);
        $this->scopes->normalize(['workspaces:read']);
    }

    /**
     * EN: Issues and authenticates a real key using scopes from enabled module descriptors.
     *
     * HR: Izdaje i autentificira stvarni ključ sa scopeovima iz descriptora uključenih modula.
     *
     * @param list<string> $scopes
     */
    private function request(int $userId, array $scopes): Request
    {
        $issued = $this->keys->issue(
            $userId,
            'Scope regression',
            '',
            $this->scopes->normalize($scopes),
            [],
            null,
            $userId,
        );
        $identity = $this->keys->authenticate($issued->plainTextToken);
        $this->assertInstanceOf(AuthApiIdentity::class, $identity);

        return (new Request('GET', 'https://example.invalid/api/v1/me/personal-workspace'))
            ->withAttribute(ModuleApi::IDENTITY_ATTRIBUTE, $identity);
    }

    /**
     * EN: Inserts a normal non-administrator account only in the temporary database.
     *
     * HR: Umeće običan neadministratorski račun samo u privremenu bazu.
     */
    private function insertUser(string $login): int
    {
        $this->database->table(ModuleAuth::TABLE_AUTH_USERS)->insert([
            'login_identifier' => $login, 'password_hash' => null, 'is_admin' => false, 'is_active' => true,
            'auth_source' => 'local', 'must_change_password' => false,
            'created_at' => '2026-11-06 12:00:00', 'updated_at' => '2026-11-06 12:00:00',
        ]);

        return (int)$this->database->lastInsertId();
    }

    /**
     * EN: Locates the real dependency for its migration and advertised API descriptor.
     *
     * HR: Pronalazi stvarnu ovisnost radi migracije i oglašenog API descriptora.
     */
    private function moduleRoot(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        if (!is_string($file)) {
            throw new RuntimeException('Dependency source unavailable.');
        }

        return dirname($file, 3);
    }
}
