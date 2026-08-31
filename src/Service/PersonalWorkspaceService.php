<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleUser\Event\PersonalWorkspaceChanged;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Throwable;

use function array_filter;
use function array_unique;
use function array_values;
use function date;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_scalar;
use function is_string;
use function strtolower;
use function trim;

/**
 * HR: Upravlja osobnim područjima kao običnim ograničenim Workspaceovima uz
 *     stabilno mapiranje korisnika. Workspace ostaje jedini vlasnik ACL logike.
 * EN: Manages personal Workspaces as ordinary restricted Workspaces with a stable
 *     user mapping. Workspace remains the sole owner of ACL logic.
 */
final readonly class PersonalWorkspaceService
{
    private const SETTING_AUTOMATIC_CREATION = 'personal_workspace.auto_create';

    /** HR: Prima javne servise modula o kojima Simbioza User već ovisi. EN: Receives public services of existing module dependencies. */
    public function __construct(
        private Database $database,
        private WorkspaceRepository $workspaces,
        private AuthUserService $users,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /** HR: Provjerava jesu li sve tri tablice nadogradnje spremne. EN: Checks whether all three upgrade tables are ready. */
    public function tablesReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleSimbiozaUser::TABLE_SETTINGS)
            && $schema->hasTable(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES)
            && $schema->hasTable(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACE_POLICIES);
    }

    /** HR: Vraća globalno pravilo; nakon instalacije je uključeno. EN: Returns the global rule; it is enabled after installation. */
    public function automaticCreationEnabled(): bool
    {
        if (!$this->tablesReady()) {
            return false;
        }

        $row = $this->database->table(ModuleSimbiozaUser::TABLE_SETTINGS)
            ->where('setting_key', '=', self::SETTING_AUTOMATIC_CREATION)
            ->first();

        return !is_array($row) || $this->boolValue($row['setting_value'] ?? true);
    }

    /** HR: Sprema globalno administratorsko pravilo. EN: Saves the global administrator rule. */
    public function setAutomaticCreationEnabled(bool $enabled, int $actorUserId = 0): void
    {
        $this->assertReady();
        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleSimbiozaUser::TABLE_SETTINGS)->upsert(
            [[
                'setting_key' => self::SETTING_AUTOMATIC_CREATION,
                'setting_value' => $enabled ? '1' : '0',
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['setting_key'],
            ['setting_value', 'updated_at'],
        );
        $this->dispatch(new PersonalWorkspaceChanged(
            $actorUserId,
            0,
            $enabled ? 'automatic_creation_enabled' : 'automatic_creation_disabled',
        ));
    }

    /** HR: Vraća smije li se područje automatski izraditi tom korisniku. EN: Returns whether automatic creation is allowed for this user. */
    public function automaticCreationEnabledForUser(int $userId): bool
    {
        if ($userId <= 0 || !$this->tablesReady()) {
            return false;
        }

        $row = $this->database->table(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACE_POLICIES)
            ->where('user_id', '=', $userId)
            ->first();

        return !is_array($row) || $this->boolValue($row['auto_create_enabled'] ?? true);
    }

    /** HR: Sprema korisničku iznimku koju smije uređivati samo administratorski adapter. EN: Saves a per-user exception edited only by the administrator adapter. */
    public function setAutomaticCreationForUser(int $userId, bool $enabled, int $actorUserId): void
    {
        $this->assertReady();
        if (!is_array($this->users->findByIdIncludingInactive($userId))) {
            throw new RuntimeException(__('Korisnik nije pronađen.'));
        }

        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACE_POLICIES)->upsert(
            [[
                'user_id' => $userId,
                'auto_create_enabled' => $enabled,
                'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id'],
            ['auto_create_enabled', 'updated_by_user_id', 'updated_at'],
        );
        $this->dispatch(new PersonalWorkspaceChanged(
            $actorUserId,
            $userId,
            $enabled ? 'user_automatic_creation_enabled' : 'user_automatic_creation_disabled',
        ));
    }

    /**
     * HR: Nakon uspješne prijave izrađuje područje samo kada oba pravila to dopuštaju.
     * EN: Creates the space after successful sign-in only when both policies allow it.
     *
     * @return array<string,mixed>|null
     */
    public function ensureAfterLogin(int $userId): ?array
    {
        $mapped = $this->forUser($userId);
        if (is_array($mapped)) {
            $this->grantAllPermissions($mapped, $userId);

            return $mapped;
        }

        if (
            !$this->automaticCreationEnabled()
            || !$this->automaticCreationEnabledForUser($userId)
        ) {
            return null;
        }

        return $this->ensureForUser($userId, $userId, true);
    }

    /**
     * HR: Izrađuje nedostajuće osobno područje, njegovu korisniku odmah daje
     *     sva prava i sprema mapiranje odvojeno od općih područja.
     * EN: Creates a missing personal Workspace and stores a mapping separate from
     *     general Workspaces while immediately granting its user every permission.
     *
     * @return array<string,mixed>|null
     */
    public function ensureForUser(int $userId, int $actorUserId, bool $automatic): ?array
    {
        $this->assertReady();
        $mapped = $this->forUser($userId);
        if (is_array($mapped)) {
            $this->grantAllPermissions($mapped, $userId);

            return $mapped;
        }

        $user = $this->users->findById($userId);
        if (!is_array($user)) {
            return null;
        }

        $login = $this->text($user['login_identifier'] ?? '');
        $ownerName = $this->ownerName($user, $userId);
        $name = sprintf(__('Područje od: %s'), $ownerName);
        $description = sprintf(__('Osobno područje korisnika %s.'), $ownerName);
        $workspaceValues = [
            'name' => $name,
            'name_translations' => [
                'hr' => sprintf('Područje od: %s', $ownerName),
                'en' => sprintf('Workspace of: %s', $ownerName),
            ],
            'slug' => 'osobno-' . ($login !== '' ? $login : $ownerName),
            'description' => $description,
            'description_translations' => [
                'hr' => sprintf('Osobno područje korisnika %s.', $ownerName),
                'en' => sprintf('Personal workspace of %s.', $ownerName),
            ],
            'visibility' => 'restricted',
            'tree_visibility' => 'inherit',
            'contents_visibility' => 'inherit',
        ];
        $effectiveActorUserId = $actorUserId > 0 ? $actorUserId : $userId;

        $now = date('Y-m-d H:i:s');
        try {
            $workspaceId = $this->database->transaction(function () use (
                $workspaceValues,
                $effectiveActorUserId,
                $userId,
                $automatic,
                $now,
            ): int {
                $workspace = $this->workspaces->saveWorkspace(
                    $workspaceValues,
                    $effectiveActorUserId,
                    $userId,
                );
                $workspaceId = is_numeric($workspace['id'] ?? null) ? (int)$workspace['id'] : 0;
                if ($workspaceId <= 0) {
                    throw new RuntimeException(__('Osobno područje nije moguće povezati s korisnikom.'));
                }

                $this->database->table(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES)->insert([
                    'user_id' => $userId,
                    'workspace_id' => $workspaceId,
                    'created_automatically' => $automatic,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $workspaceId;
            });
        } catch (Throwable $throwable) {
            // HR: Transakcija uklanja i eventualno paralelno kreirano područje
            //     bez mapiranja. Zatim koristimo mapiranje drugog zahtjeva.
            // EN: The transaction also removes any concurrently created space
            //     without a mapping. We then use the other request's mapping.
            $concurrent = $this->forUser($userId);
            if (is_array($concurrent)) {
                $this->grantAllPermissions($concurrent, $userId);

                return $concurrent;
            }

            throw $throwable;
        }

        if (!is_int($workspaceId) || $workspaceId <= 0) {
            throw new RuntimeException(__('Osobno područje nije moguće povezati s korisnikom.'));
        }

        $this->dispatch(new PersonalWorkspaceChanged(
            $effectiveActorUserId,
            $userId,
            'created',
            $workspaceId,
        ));

        return $this->forUser($userId);
    }

    /**
     * HR: Vraća mapiranje s Workspace podacima, uključujući soft-obrisano područje.
     * EN: Returns the mapping with Workspace data, including a soft-deleted space.
     *
     * @return (array<string,mixed>&array{workspace:array<string,mixed>,is_deleted:bool})|null
     */
    public function forUser(int $userId): ?array
    {
        if ($userId <= 0 || !$this->tablesReady()) {
            return null;
        }

        $mapping = $this->database->table(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES)
            ->where('user_id', '=', $userId)
            ->first();
        if (!is_array($mapping) || !is_numeric($mapping['workspace_id'] ?? null)) {
            return null;
        }

        $workspace = $this->workspaces->findWorkspaceById((int)$mapping['workspace_id'], true);
        if (!is_array($workspace)) {
            return null;
        }

        $result = [];
        foreach ($mapping as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        $result['workspace'] = $workspace;
        $result['is_deleted'] = $this->boolValue($workspace['is_deleted'] ?? false);

        return $result;
    }

    /**
     * HR: Izrađuje osobna područja aktivnim postojećim korisnicima koji nisu isključeni.
     * EN: Creates personal Workspaces for active existing users who are not excluded.
     *
     * @return array{created:int,existing:int,disabled:int,failed:int}
     */
    public function provisionExistingUsers(int $actorUserId): array
    {
        $result = ['created' => 0, 'existing' => 0, 'disabled' => 0, 'failed' => 0];
        foreach ($this->activeUsers() as $user) {
            $userId = is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
            if ($userId <= 0) {
                continue;
            }

            $mapped = $this->forUser($userId);
            if (is_array($mapped)) {
                $this->grantAllPermissions($mapped, $userId);
                ++$result['existing'];
                continue;
            }

            if (!$this->automaticCreationEnabledForUser($userId)) {
                ++$result['disabled'];
                continue;
            }

            try {
                is_array($this->ensureForUser($userId, $actorUserId, false))
                    ? ++$result['created']
                    : ++$result['failed'];
            } catch (Throwable) {
                ++$result['failed'];
            }
        }

        return $result;
    }

    /**
     * HR: Sastavlja administratorski pregled bez upita po retku.
     * EN: Builds the administrator overview without per-row queries.
     *
     * @return list<array<string,mixed>>
     */
    public function administrationRows(): array
    {
        $mappings = [];
        if ($this->tablesReady()) {
            foreach ($this->database->table(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES)->get() as $row) {
                if (is_array($row) && is_numeric($row['user_id'] ?? null)) {
                    $mappings[(int)$row['user_id']] = $row;
                }
            }
        }

        $policies = [];
        if ($this->tablesReady()) {
            foreach ($this->database->table(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACE_POLICIES)->get() as $row) {
                if (is_array($row) && is_numeric($row['user_id'] ?? null)) {
                    $policies[(int)$row['user_id']] = $this->boolValue($row['auto_create_enabled'] ?? true);
                }
            }
        }

        $workspaceRows = [...$this->workspaces->activeWorkspaces(), ...$this->workspaces->deletedWorkspaces()];
        $workspaceById = [];
        foreach ($workspaceRows as $workspace) {
            if (is_numeric($workspace['id'] ?? null)) {
                $workspaceById[(int)$workspace['id']] = $workspace;
            }
        }

        $rows = [];
        foreach ($this->activeUsers() as $user) {
            $userId = is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
            if ($userId <= 0) {
                continue;
            }

            $mapping = $mappings[$userId] ?? null;
            $workspaceId = is_array($mapping) && is_numeric($mapping['workspace_id'] ?? null)
                ? (int)$mapping['workspace_id']
                : 0;
            $rows[] = [
                ...$user,
                'auto_create_enabled' => $policies[$userId] ?? true,
                'personal_workspace' => $workspaceById[$workspaceId] ?? null,
                'personal_workspace_mapping' => $mapping,
            ];
        }

        return $rows;
    }

    /**
     * HR: Vraća vlasničke oznake samo za zatražena osobna područja jednim
     *     spojenim upitom, bez učitavanja svih Auth korisnika i grupa.
     * EN: Returns owner labels only for the requested personal Workspaces in one
     *     joined query, without loading every Auth user and group.
     *
     * @param list<int> $workspaceIds
     * @return array<int,string>
     */
    public function presentationOwners(array $workspaceIds): array
    {
        $workspaceIds = array_values(array_unique(array_filter(
            $workspaceIds,
            static fn(int $workspaceId): bool => $workspaceId > 0,
        )));
        if ($workspaceIds === [] || !$this->tablesReady()) {
            return [];
        }

        $mappingTable = ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES;
        $userTable = ModuleAuth::TABLE_AUTH_USERS;
        $attributeTable = ModuleAuth::TABLE_AUTH_USER_ATTRIBUTE_VALUES;
        $rows = $this->database
            ->table($mappingTable)
            ->select([
                $mappingTable . '.workspace_id AS workspace_id',
                $mappingTable . '.user_id AS user_id',
                $userTable . '.login_identifier AS login_identifier',
                $attributeTable . '.field_key AS attribute_key',
                $attributeTable . '.value_text AS attribute_value',
            ])
            ->join(
                $userTable,
                $userTable . '.id',
                '=',
                $mappingTable . '.user_id',
            )
            ->leftJoin(
                $attributeTable,
                $attributeTable . '.user_id',
                '=',
                $mappingTable . '.user_id',
            )
            ->whereIn($mappingTable . '.workspace_id', $workspaceIds)
            ->get();

        $ownerRows = [];
        foreach ($rows as $row) {
            if (
                !is_array($row)
                || !is_numeric($row['workspace_id'] ?? null)
                || !is_numeric($row['user_id'] ?? null)
            ) {
                continue;
            }

            $workspaceId = (int)$row['workspace_id'];
            $ownerRows[$workspaceId] ??= [
                'login_identifier' => $this->text($row['login_identifier'] ?? ''),
                'display_name' => '',
                'user_id' => (int)$row['user_id'],
            ];
            if ($this->text($row['attribute_key'] ?? '') === 'display_name') {
                $ownerRows[$workspaceId]['display_name'] = $this->text($row['attribute_value'] ?? '');
            }
        }

        $owners = [];
        foreach ($ownerRows as $workspaceId => $ownerRow) {
            $ownerName = $this->ownerName($ownerRow, $ownerRow['user_id']);
            if ($ownerName !== '') {
                $owners[$workspaceId] = $ownerName;
            }
        }

        return $owners;
    }

    /**
     * HR: Vraća aktivne Auth korisnike pogodne za izradu područja.
     * EN: Returns active Auth users eligible for space provisioning.
     *
     * @return list<array<string,mixed>>
     */
    private function activeUsers(): array
    {
        return array_values(array_filter(
            $this->users->listUsersForSetup(),
            fn(mixed $user): bool => is_array($user) && $this->boolValue($user['is_active'] ?? false),
        ));
    }

    /**
     * HR: Idempotentno osigurava svih šest prava korisniku mapiranog osobnog
     *     područja, uključujući područja nastala prije uvođenja ovog pravila.
     * EN: Idempotently grants all six permissions to the mapped personal
     *     Workspace user, including Workspaces created before this rule.
     *
     * @param array<string,mixed> $mapped
     */
    private function grantAllPermissions(array $mapped, int $userId): void
    {
        $workspaceId = is_numeric($mapped['workspace_id'] ?? null)
            ? (int)$mapped['workspace_id']
            : 0;
        if ($workspaceId > 0) {
            $this->workspaces->grantWorkspaceManagement($workspaceId, $userId);
        }
    }

    /**
     * HR: Vraća stabilnu ljudsku oznaku vlasnika uz login kao sigurnu rezervu.
     * EN: Returns a stable human owner label with the login as a safe fallback.
     *
     * @param array<string,mixed> $user
     */
    private function ownerName(array $user, int $userId): string
    {
        $displayName = $this->text($user['display_name'] ?? '');
        if ($displayName !== '') {
            return $displayName;
        }

        $login = $this->text($user['login_identifier'] ?? '');

        return $login !== '' ? $login : sprintf(__('Korisnik %d'), $userId);
    }

    /** HR: Zaustavlja poslovnu operaciju prije parcijalnog upisa. EN: Stops the business operation before a partial write. */
    private function assertReady(): void
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException(__('Migracija osobnih područja nije primijenjena.'));
        }
    }

    /** HR: Pretvara vrijednost iz baze ili forme u boolean. EN: Converts a database or form value to boolean. */
    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value !== 0;
        }

        return is_scalar($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** HR: Sigurno pretvara skalarnu vrijednost u tekst. EN: Safely converts a scalar value to text. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** HR: Sekundarni audit kanal ne smije poništiti spremljenu promjenu. EN: The secondary audit channel must not roll back a saved change. */
    private function dispatch(PersonalWorkspaceChanged $event): void
    {
        try {
            $this->events?->dispatch($event);
        } catch (Throwable) {
            // HR: Poslovna promjena je već spremljena. EN: The business change is already stored.
        }
    }
}
