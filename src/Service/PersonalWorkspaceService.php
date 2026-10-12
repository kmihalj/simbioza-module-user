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
 * EN: Manages personal Workspaces as ordinary restricted Workspaces with a stable
 *     user mapping. Workspace remains the sole owner of ACL logic.
 *
 * HR: Upravlja osobnim područjima kao običnim ograničenim Workspaceovima uz
 *     stabilno mapiranje korisnika. Workspace ostaje jedini vlasnik ACL logike.
 */
final readonly class PersonalWorkspaceService
{
    private const SETTING_AUTOMATIC_CREATION = 'personal_workspace.auto_create';

    private const SETTING_SELF_CREATION = 'personal_workspace.self_create';

    /**
     * EN: Receives public services of existing module dependencies.
     *
     * HR: Prima javne servise modula o kojima Simbioza User već ovisi.
     */
    public function __construct(
        private Database $database,
        private WorkspaceRepository $workspaces,
        private AuthUserService $users,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * EN: Checks whether all three upgrade tables are ready.
     *
     * HR: Provjerava jesu li sve tri tablice nadogradnje spremne.
     */
    public function tablesReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleSimbiozaUser::TABLE_SETTINGS)
            && $schema->hasTable(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES);
    }

    /**
     * EN: Returns the global rule; it is enabled after installation.
     *
     * HR: Vraća globalno pravilo; nakon instalacije je uključeno.
     */
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

    /**
     * EN: Returns the stored self-creation rule; it is disabled by default.
     *
     * HR: Vraća spremljeno pravilo samostalne izrade; zadano je isključeno.
     */
    public function selfCreationEnabled(): bool
    {
        if (!$this->tablesReady()) {
            return false;
        }

        $row = $this->database->table(ModuleSimbiozaUser::TABLE_SETTINGS)
            ->where('setting_key', '=', self::SETTING_SELF_CREATION)
            ->first();

        return is_array($row) && $this->boolValue($row['setting_value'] ?? false);
    }

    /**
     * EN: Self-creation applies only while automatic creation is disabled.
     *
     * HR: Samostalna izrada vrijedi samo kada automatska nije uključena.
     */
    public function userCreationEnabled(): bool
    {
        return !$this->automaticCreationEnabled() && $this->selfCreationEnabled();
    }

    /**
     * EN: Saves the global administrator rule.
     *
     * HR: Sprema globalno administratorsko pravilo.
     */
    public function setAutomaticCreationEnabled(bool $enabled, int $actorUserId = 0): void
    {
        $this->setCreationSettings($enabled, $this->selfCreationEnabled(), $actorUserId);
    }

    /**
     * EN: Atomically saves automatic and user-driven creation; automation
     *     always takes precedence and disables the user switch.
     *
     * HR: Atomski sprema automatsku i korisničku izradu; automatika uvijek
     *     nadjačava i isključuje korisnički prekidač.
     */
    public function setCreationSettings(
        bool $automaticCreation,
        bool $selfCreation,
        int $actorUserId = 0,
    ): void {
        $this->assertReady();
        $selfCreation = !$automaticCreation && $selfCreation;
        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleSimbiozaUser::TABLE_SETTINGS)->upsert(
            [
                [
                    'setting_key' => self::SETTING_AUTOMATIC_CREATION,
                    'setting_value' => $automaticCreation ? '1' : '0',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'setting_key' => self::SETTING_SELF_CREATION,
                    'setting_value' => $selfCreation ? '1' : '0',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['setting_key'],
            ['setting_value', 'updated_at'],
        );
        $this->dispatch(new PersonalWorkspaceChanged(
            $actorUserId,
            0,
            'creation_settings_updated',
        ));
    }

    /**
     * EN: Creates the space after successful sign-in only when both policies allow it.
     *
     * HR: Nakon uspješne prijave izrađuje područje samo kada oba pravila to dopuštaju.
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

        if (!$this->automaticCreationEnabled()) {
            return null;
        }

        return $this->ensureForUser($userId, $userId, true);
    }

    /**
     * EN: Allows a user to create only their own Workspace and only under the
     *     current global setting. An existing Workspace remains available.
     *
     * HR: Korisniku dopušta izradu samo vlastitog područja i samo prema
     *     trenutačnoj globalnoj postavci. Postojeće područje ostaje dostupno.
     *
     * @return array<string,mixed>
     */
    public function createOwnWorkspace(int $userId): array
    {
        $this->assertReady();
        $mapped = $this->forUser($userId);
        if (is_array($mapped)) {
            if ((bool)($mapped['is_deleted'] ?? false)) {
                throw new RuntimeException(__('Vaše osobno područje je obrisano. Obratite se administratoru.'));
            }

            $this->grantAllPermissions($mapped, $userId);

            return $mapped;
        }

        if (!$this->userCreationEnabled()) {
            throw new RuntimeException(__('Samostalna izrada osobnog područja nije omogućena.'));
        }

        $created = $this->ensureForUser($userId, $userId, false);
        if (!is_array($created)) {
            throw new RuntimeException(__('Osobno područje nije moguće izraditi.'));
        }

        return $created;
    }

    /**
     * EN: Creates a missing personal Workspace and stores a mapping separate from
     *     general Workspaces while immediately granting its user every permission.
     *
     * HR: Izrađuje nedostajuće osobno područje, njegovu korisniku odmah daje
     *     sva prava i sprema mapiranje odvojeno od općih područja.
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
            // EN: The transaction also removes any concurrently created space
            //     without a mapping. We then use the other request's mapping.
            //
            // HR: Transakcija uklanja i eventualno paralelno kreirano područje
            //     bez mapiranja. Zatim koristimo mapiranje drugog zahtjeva.
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
     * EN: Returns the mapping with Workspace data, including a soft-deleted space.
     *
     * HR: Vraća mapiranje s Workspace podacima, uključujući soft-obrisano područje.
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
     * EN: Builds the administrator overview without per-row queries.
     *
     * HR: Sastavlja administratorski pregled bez upita po retku.
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

        $workspaceRows = [...$this->workspaces->activeWorkspaces(), ...$this->workspaces->deletedWorkspaces()];
        $workspaceById = [];
        foreach ($workspaceRows as $workspace) {
            if (is_numeric($workspace['id'] ?? null)) {
                $workspaceById[(int)$workspace['id']] = $workspace;
            }
        }

        $rows = [];
        foreach ($this->users->listUsersForSetup() as $user) {
            $userId = is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
            if ($userId <= 0 || !isset($mappings[$userId])) {
                continue;
            }

            $mapping = $mappings[$userId];
            $workspaceId = is_numeric($mapping['workspace_id'] ?? null)
                ? (int)$mapping['workspace_id']
                : 0;
            if (!isset($workspaceById[$workspaceId])) {
                continue;
            }

            $rows[] = [
                ...$user,
                'personal_workspace' => $workspaceById[$workspaceId],
                'personal_workspace_mapping' => $mapping,
            ];
        }

        return $rows;
    }

    /**
     * EN: Returns owner labels only for the requested personal Workspaces in one
     *     joined query, without loading every Auth user and group.
     *
     * HR: Vraća vlasničke oznake samo za zatražena osobna područja jednim
     *     spojenim upitom, bez učitavanja svih Auth korisnika i grupa.
     *
     * @param list<int> $workspaceIds
     * @return array<int,string>
     */
    public function presentationOwners(array $workspaceIds): array
    {
        $owners = [];
        foreach ($this->presentationOwnerRows($workspaceIds) as $workspaceId => $owner) {
            $owners[$workspaceId] = $owner['name'];
        }

        return $owners;
    }

    /**
     * EN: Returns the stable owner ID and display label for batched personal-
     *     Workspace presentation. The ID lets the generic Workspace UI retain
     *     the actor's own space without guessing from its name or slug.
     *
     * HR: Vraća stabilni ID i prikaznu oznaku vlasnika za grupni prikaz osobnih
     *     područja. ID omogućuje generičkom Workspace sučelju da vlastito
     *     područje zadrži u glavnom popisu bez pogađanja prema nazivu ili slugu.
     *
     * @param list<int> $workspaceIds
     * @return array<int,array{user_id:int,name:string}>
     */
    public function presentationOwnerRows(array $workspaceIds): array
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
                $owners[$workspaceId] = [
                    'user_id' => $ownerRow['user_id'],
                    'name' => $ownerName,
                ];
            }
        }

        return $owners;
    }

    /**
     * EN: Idempotently grants all six permissions to the mapped personal
     *     Workspace user, including Workspaces created before this rule.
     *
     * HR: Idempotentno osigurava svih šest prava korisniku mapiranog osobnog
     *     područja, uključujući područja nastala prije uvođenja ovog pravila.
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
     * EN: Returns a stable human owner label with the login as a safe fallback.
     *
     * HR: Vraća stabilnu ljudsku oznaku vlasnika uz login kao sigurnu rezervu.
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

    /**
     * EN: Stops the business operation before a partial write.
     *
     * HR: Zaustavlja poslovnu operaciju prije parcijalnog upisa.
     */
    private function assertReady(): void
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException(__('Migracija osobnih područja nije primijenjena.'));
        }
    }

    /**
     * EN: Converts a database or form value to boolean.
     *
     * HR: Pretvara vrijednost iz baze ili forme u boolean.
     */
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

    /**
     * EN: Safely converts a scalar value to text.
     *
     * HR: Sigurno pretvara skalarnu vrijednost u tekst.
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * EN: The secondary audit channel must not roll back a saved change.
     *
     * HR: Sekundarni audit kanal ne smije poništiti spremljenu promjenu.
     */
    private function dispatch(PersonalWorkspaceChanged $event): void
    {
        try {
            $this->events?->dispatch($event);
        } catch (Throwable) {
            // EN: The business change is already stored.
            //
            // HR: Poslovna promjena je već spremljena.
        }
    }
}
