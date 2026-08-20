<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Backup;

use AaiEduHr\HeartPhrameModuleAuth\Backup\AuthBackupIdentityResolver;
use AaiEduHr\HeartPhrameModuleBackup\Contract\BackupProviderInterface;
use AaiEduHr\HeartPhrameModuleBackup\Exception\BackupException;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveWriter;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupComponentGroup;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupPreflightResult;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupValue;
use AaiEduHr\HeartPhrameModuleCalendar\ModuleCalendar;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use AaiEduHr\SimbiozaModuleUser\Service\EmbeddedCalendarPageResolver;

/**
 * HR: Prenosi samo praćenja vezana uz područja, bez globalnih osobnih
 *     postavki korisnika i bez prolaznog reda dostave. Time upravitelj područja
 *     ne može izvesti postavke korisnika koje nisu dio njegova područja.
 *
 * EN: Transfers only Workspace-related follows, without users' global personal
 *     preferences or the transient delivery queue. A Workspace manager therefore
 *     cannot export user settings unrelated to that Workspace.
 */
final readonly class SimbiozaUserWorkspaceBackupProvider implements BackupProviderInterface
{
    /**
     * HR: Prima bazu, prenosive identitete i opcionalni resolver kalendara.
     * EN: Receives the database, portable identities, and optional calendar resolver.
     *
     * @param list<string> $dependencies
     * @param list<string> $scopes
     */
    public function __construct(
        private Database $database,
        private AuthBackupIdentityResolver $identities,
        private string $id,
        private array $dependencies,
        private array $scopes,
        private ?EmbeddedCalendarPageResolver $calendarPages = null,
    ) {
        if ($this->id === '' || $this->scopes === []) {
            throw new BackupException('Workspace follow backup provider requires an ID and at least one scope.');
        }
    }

    /** HR: Opisuje scoped praćenja područja. EN: Describes scoped Workspace follows. */
    public function metadata(): BackupProviderMetadata
    {
        return new BackupProviderMetadata(
            $this->id,
            ModuleSimbiozaUser::PACKAGE_NAME,
            1,
            ['hr' => 'Praćenja i obavijesti područja', 'en' => 'Workspace follows and notifications'],
            $this->dependencies,
            $this->scopes,
            true,
            true,
            [ModuleWorkspace::PACKAGE_NAME],
            componentGroups: [BackupComponentGroup::WORKSPACES],
        );
    }

    /**
     * HR: Izvozi izravna praćenja područja te postavke dostave i isključenja
     *     kalendara ugrađenih u njegove objavljene stranice.
     * EN: Exports direct Workspace follows plus delivery overrides and exclusions
     *     for calendars embedded in its published pages.
     */
    public function export(BackupExportContext $context, BackupArchiveWriter $writer): void
    {
        // HR: Ako je odabrana i cjelina Korisnici, njezin potpuni provider već
        //     sadrži iste retke pa izbjegavamo dvostruki zapis.
        // EN: When Users is also selected, its complete provider already owns
        //     the same rows, so avoid writing them twice.
        if (
            $context->scope->type === BackupScope::COMPONENT
            && in_array('simbioza-user', $context->selectedProviders, true)
        ) {
            $writer->writeRecord($this->id, 'delegated', ['provider' => 'simbioza-user']);
            return;
        }

        $workspaces = $this->sourceWorkspaces($context->scope);
        if ($workspaces === []) {
            return;
        }

        if ($context->scope->type === BackupScope::WORKSPACE) {
            $sourceId = array_key_first($workspaces);
            $source = $workspaces[$sourceId] ?? null;
            if (!is_array($source)) {
                throw new BackupException('Unable to serialize the Workspace follow scope.');
            }

            $writer->writeRecord($this->id, 'scope', [
                'source_workspace_id' => $sourceId,
                'workspace_slug' => BackupValue::string($source['slug'], 'workspace.slug'),
            ]);
        }

        $workspaceIds = array_keys($workspaces);
        $pages = $this->sourcePages($workspaceIds);
        $written = [];
        $direct = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
            ->whereIn('workspace_id', $workspaceIds)
            ->orderBy('id')
            ->get();
        foreach ($direct as $row) {
            if (!is_array($row) || ($row['target_type'] ?? null) === 'calendar') {
                continue;
            }

            $portable = $this->portableFollow($row, $workspaces, $pages);
            $writer->writeRecord($this->id, 'follows', $portable);
            $written[$this->followKey($row)] = true;
        }

        $calendarIds = array_values($this->linkedCalendarIds($workspaceIds));
        if ($calendarIds === [] || !class_exists(ModuleCalendar::class)) {
            return;
        }

        $calendars = $this->calendarRows($calendarIds);
        foreach ($this->calendarFollowRows($calendarIds) as $row) {
            $key = $this->followKey($row);
            if (isset($written[$key])) {
                continue;
            }

            $writer->writeRecord($this->id, 'follows', $this->portableCalendarFollow($row, $calendars));
            $written[$key] = true;
        }

        foreach ($this->calendarExclusionRows($calendarIds) as $row) {
            $calendarId = $this->positiveInteger($row['target_id'] ?? null);
            $calendar = $calendars[$calendarId] ?? null;
            $user = $this->identities->userKeyForId($row['user_id'] ?? null);
            if (!is_array($calendar) || $user === null) {
                throw new BackupException('Unable to serialize a Workspace calendar follow exclusion.');
            }

            $writer->writeRecord($this->id, 'follow-exclusions', [
                'user' => $user,
                'source_calendar_id' => $calendarId,
                'calendar_uuid' => BackupValue::string($calendar['uuid'], 'calendar.uuid'),
                'source' => $row['source'] ?? 'automatic',
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ]);
        }
    }

    /** HR: Provjerava tablice, oblik zapisa i korisničke identitete. EN: Checks tables, record shape, and user identities. */
    public function preflight(BackupImportContext $context, BackupArchiveReader $reader): BackupPreflightResult
    {
        $errors = [];
        foreach ([ModuleSimbiozaUser::TABLE_FOLLOWS, ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS] as $table) {
            if (!$this->database->schema()->hasTable($table)) {
                $errors[] = 'Required Simbioza User table is missing: ' . $table;
            }
        }

        foreach (['follows', 'follow-exclusions'] as $dataset) {
            foreach ($reader->records($this->id, $dataset) as $row) {
                $user = is_scalar($row['user'] ?? null) ? trim((string)$row['user']) : '';
                if ($user === '' || $this->identities->userIdForKey($user) === null) {
                    $errors[] = 'Workspace follow references an unavailable user: '
                        . ($user !== '' ? $user : '(empty)');
                }
            }
        }

        return new BackupPreflightResult(
            array_values(array_unique($errors)),
            [],
            [
                'delegated.records' => $this->datasetCount($reader, 'delegated'),
                'scope.records' => $this->datasetCount($reader, 'scope'),
                'follows.records' => $this->datasetCount($reader, 'follows'),
                'follow-exclusions.records' => $this->datasetCount($reader, 'follow-exclusions'),
            ],
        );
    }

    /** HR: Scoped brisanje čeka mapiranja koja stvaraju raniji provideri. EN: Scoped deletion waits for mappings created by earlier providers. */
    public function prepareImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
    }

    /** HR: Vraća praćenja uz prenosivo mapiranje područja, stranica i kalendara. EN: Restores follows with portable Workspace, page, and calendar mapping. */
    public function import(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        if ($this->datasetCount($reader, 'delegated') > 0) {
            return;
        }

        $this->clearDirectFollowsForReplace($context, $reader);

        foreach ($reader->records($this->id, 'follows') as $row) {
            $userId = $this->importUser($row['user'] ?? null);
            $target = $this->importTarget($row, $context);
            $uuid = $context->conflictMode === BackupImportContext::CONFLICT_COPY
                ? $this->uuid()
                : BackupValue::string($row['uuid'], 'follow.uuid');
            $values = [
                'uuid' => $uuid,
                'user_id' => $userId,
                'target_type' => $target['type'],
                'target_id' => (string)$target['id'],
                'workspace_id' => $target['workspace_id'],
                'page_id' => $target['page_id'],
                'document_id' => $target['document_id'],
                'label_snapshot' => $row['label_snapshot'] ?? null,
                'email_mode_override' => $row['email_mode_override'] ?? null,
                'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
            ];
            $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)->upsert(
                $values,
                ['user_id', 'target_type', 'target_id'],
                [
                    'workspace_id',
                    'page_id',
                    'document_id',
                    'label_snapshot',
                    'email_mode_override',
                    'updated_at',
                ],
            );
        }

        foreach ($reader->records($this->id, 'follow-exclusions') as $row) {
            $userId = $this->importUser($row['user'] ?? null);
            $calendarId = $this->importCalendarId($row, $context);
            $values = [
                'user_id' => $userId,
                'target_type' => 'calendar',
                'target_id' => (string)$calendarId,
                'source' => $row['source'] ?? 'automatic',
                'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
            ];
            $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)->upsert(
                $values,
                ['user_id', 'target_type', 'target_id'],
                ['source', 'updated_at'],
            );
            $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
                ->where('user_id', '=', $userId)
                ->where('target_type', '=', 'calendar')
                ->where('target_id', '=', (string)$calendarId)
                ->delete();
        }
    }

    /** HR: Nema dodatnog stanja nakon DB importa. EN: There is no extra state after database import. */
    public function finalizeImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
    }

    /** HR: DB transakcija obavlja rollback. EN: The database transaction performs rollback. */
    public function abortImport(BackupImportContext $context): void
    {
    }

    /**
     * HR: Vraća područja uključena u odabrani opseg.
     * EN: Returns Workspaces included in the selected scope.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sourceWorkspaces(BackupScope $scope): array
    {
        $query = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->orderBy('id');
        if ($scope->type === BackupScope::WORKSPACE) {
            $identifier = trim((string)$scope->identifier);
            $row = $query->where('slug', '=', $identifier)->first()
                ?? $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
                    ->where('uuid', '=', $identifier)
                    ->first();
            if (!is_array($row) || !is_numeric($row['id'] ?? null)) {
                throw new BackupException('Workspace does not exist: ' . $identifier);
            }

            return [(int)$row['id'] => $row];
        }

        $rows = [];
        foreach ($query->get() as $row) {
            if (is_array($row) && is_numeric($row['id'] ?? null)) {
                $rows[(int)$row['id']] = $row;
            }
        }

        return $rows;
    }

    /**
     * HR: Učitava stabilne UUID-ove stranica bez N+1 upita.
     * EN: Loads stable page UUIDs without N+1 queries.
     *
     * @param list<int> $workspaceIds
     * @return array<int,array<string,mixed>>
     */
    private function sourcePages(array $workspaceIds): array
    {
        $pages = [];
        foreach (
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->whereIn('workspace_id', $workspaceIds)
                ->get() as $row
        ) {
            if (is_array($row) && is_numeric($row['id'] ?? null)) {
                $pages[(int)$row['id']] = $row;
            }
        }

        return $pages;
    }

    /**
     * HR: Pretvara izravno praćenje u zapis bez lokalnih brojčanih identiteta.
     * EN: Converts a direct follow into a record without local numeric identities.
     *
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $workspaces
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,mixed>
     */
    private function portableFollow(array $row, array $workspaces, array $pages): array
    {
        $workspaceId = $this->positiveInteger($row['workspace_id'] ?? null);
        $workspace = $workspaces[$workspaceId] ?? null;
        $pageId = $this->positiveInteger($row['page_id'] ?? null, true);
        $page = $pageId > 0 ? ($pages[$pageId] ?? null) : null;
        $user = $this->identities->userKeyForId($row['user_id'] ?? null);
        if (!is_array($workspace) || $user === null || ($pageId > 0 && !is_array($page))) {
            throw new BackupException('Unable to serialize a Workspace follow.');
        }

        return [
            'uuid' => BackupValue::string($row['uuid'], 'follow.uuid'),
            'user' => $user,
            'target_type' => BackupValue::string($row['target_type'], 'follow.target_type'),
            'target_key' => BackupValue::string($row['target_id'], 'follow.target_id'),
            'source_workspace_id' => $workspaceId,
            'workspace_slug' => BackupValue::string($workspace['slug'], 'workspace.slug'),
            'source_page_id' => $pageId > 0 ? $pageId : null,
            'page_uuid' => is_array($page) ? BackupValue::string($page['uuid'], 'page.uuid') : null,
            'document_id' => $row['document_id'] ?? null,
            'label_snapshot' => $row['label_snapshot'] ?? null,
            'email_mode_override' => $row['email_mode_override'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * HR: Pretvara praćenje kalendara u zapis sa stabilnim UUID-em.
     * EN: Converts a calendar follow into a stable-UUID record.
     *
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $calendars
     * @return array<string,mixed>
     */
    private function portableCalendarFollow(array $row, array $calendars): array
    {
        $calendarId = $this->positiveInteger($row['target_id'] ?? null);
        $calendar = $calendars[$calendarId] ?? null;
        $user = $this->identities->userKeyForId($row['user_id'] ?? null);
        if (!is_array($calendar) || $user === null) {
            throw new BackupException('Unable to serialize a Workspace calendar follow.');
        }

        return [
            'uuid' => BackupValue::string($row['uuid'], 'follow.uuid'),
            'user' => $user,
            'target_type' => 'calendar',
            'target_key' => BackupValue::string($calendar['uuid'], 'calendar.uuid'),
            'source_calendar_id' => $calendarId,
            'calendar_uuid' => BackupValue::string($calendar['uuid'], 'calendar.uuid'),
            'source_workspace_id' => null,
            'workspace_slug' => null,
            'source_page_id' => null,
            'page_uuid' => null,
            'document_id' => null,
            'label_snapshot' => $row['label_snapshot'] ?? null,
            'email_mode_override' => $row['email_mode_override'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * HR: Razrješava cilj retka nakon što su raniji provideri izgradili mape.
     * EN: Resolves a row target after earlier providers built identity maps.
     *
     * @param array<string,mixed> $row
     * @return array{type:string,id:int|string,workspace_id:?int,page_id:?int,document_id:?string}
     */
    private function importTarget(array $row, BackupImportContext $context): array
    {
        $type = BackupValue::string($row['target_type'], 'follow.target_type');
        if ($type === 'calendar') {
            return [
                'type' => $type,
                'id' => $this->importCalendarId($row, $context),
                'workspace_id' => null,
                'page_id' => null,
                'document_id' => null,
            ];
        }

        $sourceWorkspaceId = BackupValue::integer($row['source_workspace_id'], 'follow.source_workspace_id');
        $workspaceId = BackupValue::integer(
            $context->state->require(
                $context->scope->type === BackupScope::WORKSPACE ? 'workspace.id' : 'workspace.workspace',
                $sourceWorkspaceId,
            ),
            'follow.workspace_id',
        );
        $pageId = null;
        if (is_scalar($row['page_uuid'] ?? null) && trim((string)$row['page_uuid']) !== '') {
            $pageKey = $context->scope->type === BackupScope::WORKSPACE
                ? BackupValue::string($row['page_uuid'], 'follow.page_uuid')
                : BackupValue::integer($row['source_page_id'], 'follow.source_page_id');
            $pageId = BackupValue::integer(
                $context->state->require(
                    $context->scope->type === BackupScope::WORKSPACE ? 'workspace.node-id' : 'workspace.node',
                    $pageKey,
                ),
                'follow.page_id',
            );
        }

        $documentId = is_scalar($row['document_id'] ?? null) ? trim((string)$row['document_id']) : '';
        if ($documentId !== '' && $context->scope->type === BackupScope::WORKSPACE) {
            $documentId = trim((string)$context->state->resolve('editor.document-key', $documentId));
        }

        $targetId = match ($type) {
            'workspace' => $workspaceId,
            'page' => $pageId ?? throw new BackupException('Workspace page follow has no mapped page.'),
            'task_list' => BackupValue::string($row['target_key'], 'follow.target_key'),
            default => throw new BackupException('Unsupported Workspace follow target type: ' . $type),
        };

        return [
            'type' => $type,
            'id' => $targetId,
            'workspace_id' => $workspaceId,
            'page_id' => $pageId,
            'document_id' => $documentId !== '' ? $documentId : null,
        ];
    }

    /**
     * HR: Mapira prenosivi kalendar za komponentni ili pojedinačni Workspace restore.
     * EN: Maps a portable calendar for component or single-Workspace restore.
     *
     * @param array<string,mixed> $row
     */
    private function importCalendarId(array $row, BackupImportContext $context): int
    {
        $uuid = BackupValue::string($row['calendar_uuid'], 'follow.calendar_uuid');
        $namespace = $context->scope->type === BackupScope::WORKSPACE
            ? 'calendar-workspace.calendar'
            : 'calendar.calendar';
        $source = $context->scope->type === BackupScope::WORKSPACE
            ? $uuid
            : BackupValue::integer($row['source_calendar_id'], 'follow.source_calendar_id');
        $mapped = $context->state->resolve($namespace, $source);
        if ($mapped === $source && class_exists(ModuleCalendar::class)) {
            $calendar = $this->database->table(ModuleCalendar::TABLE_CALENDARS)
                ->select(['id'])
                ->where('uuid', '=', $uuid)
                ->first();
            $mapped = is_array($calendar) ? ($calendar['id'] ?? null) : null;
        }

        return BackupValue::integer($mapped, 'follow.calendar_id');
    }

    /** HR: Razrješava obveznog korisnika bez tihog prebacivanja na izvođača importa. EN: Resolves a required user without silently falling back to the importing actor. */
    private function importUser(mixed $key): int
    {
        $key = is_scalar($key) ? trim((string)$key) : '';
        $userId = $this->identities->userIdForKey($key);
        if ($userId === null) {
            throw new BackupException(
                'Unable to resolve imported Workspace follower: ' . ($key !== '' ? $key : '(empty)'),
            );
        }

        return $userId;
    }

    /** HR: Replace uklanja samo izravna praćenja ciljnog područja. EN: Replace removes only direct follows of the target Workspace. */
    private function clearDirectFollowsForReplace(
        BackupImportContext $context,
        BackupArchiveReader $reader,
    ): void {
        if ($context->conflictMode !== BackupImportContext::CONFLICT_REPLACE) {
            return;
        }

        $query = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS);
        if ($context->scope->type === BackupScope::COMPONENT) {
            $query->whereNotNull('workspace_id')->delete();
            return;
        }

        $sourceWorkspace = $this->firstDatasetWorkspaceSource($reader);
        $workspaceId = BackupValue::integer(
            $context->state->require('workspace.id', $sourceWorkspace),
            'follow.replace_workspace_id',
        );
        $query->where('workspace_id', '=', $workspaceId)->delete();
    }

    /**
     * HR: Za scoped replace iz konteksta uzima izvorni ID koji je Workspace provider već mapirao.
     * EN: For scoped replace, reads the source ID already mapped by the Workspace provider.
     */
    private function firstDatasetWorkspaceSource(BackupArchiveReader $reader): int
    {
        foreach ($reader->records($this->id, 'scope') as $row) {
            return BackupValue::integer($row['source_workspace_id'], 'follow.scope.source_workspace_id');
        }

        throw new BackupException('Workspace follow backup is missing its scope record.');
    }

    /**
     * HR: Spaja jedinstvene kalendare ugrađene u odabrana područja.
     * EN: Collects unique calendars embedded in the selected Workspaces.
     *
     * @param list<int> $workspaceIds
     * @return list<int>
     */
    private function linkedCalendarIds(array $workspaceIds): array
    {
        if (!$this->calendarPages instanceof EmbeddedCalendarPageResolver) {
            return [];
        }

        $ids = [];
        foreach ($workspaceIds as $workspaceId) {
            foreach ($this->calendarPages->calendarIdsForWorkspace($workspaceId) as $calendarId) {
                $ids[$calendarId] = true;
            }
        }

        return array_values(array_map(static fn(int|string $id): int => (int)$id, array_keys($ids)));
    }

    /**
     * HR: Učitava stabilne identitete povezanih kalendara u jednom upitu.
     * EN: Loads stable identities of linked calendars in one query.
     *
     * @param list<int> $calendarIds
     * @return array<int,array<string,mixed>>
     */
    private function calendarRows(array $calendarIds): array
    {
        $rows = [];
        foreach (
            $this->database->table(ModuleCalendar::TABLE_CALENDARS)
                ->select(['id', 'uuid'])
                ->whereIn('id', $calendarIds)
                ->get() as $row
        ) {
            if (is_array($row) && is_numeric($row['id'] ?? null)) {
                $rows[(int)$row['id']] = $row;
            }
        }

        return $rows;
    }

    /**
     * HR: Učitava praćenja povezanih kalendara bez N+1 upita.
     * EN: Loads linked-calendar follows without N+1 queries.
     *
     * @param list<int> $calendarIds
     * @return list<array<string,mixed>>
     */
    private function calendarFollowRows(array $calendarIds): array
    {
        $rows = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
            ->where('target_type', '=', 'calendar')
            ->whereIn('target_id', array_map(static fn(int $id): string => (string)$id, $calendarIds))
            ->orderBy('id')
            ->get();

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * HR: Učitava izričita isključenja automatskog praćenja kalendara.
     * EN: Loads explicit exclusions from automatic calendar following.
     *
     * @param list<int> $calendarIds
     * @return list<array<string,mixed>>
     */
    private function calendarExclusionRows(array $calendarIds): array
    {
        $rows = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)
            ->where('target_type', '=', 'calendar')
            ->whereIn('target_id', array_map(static fn(int $id): string => (string)$id, $calendarIds))
            ->orderBy('id')
            ->get();

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * HR: Gradi jedinstveni ključ praćenja radi deduplikacije izvoza.
     * EN: Builds a unique follow key for export deduplication.
     *
     * @param array<string,mixed> $row
     */
    private function followKey(array $row): string
    {
        return $this->scalarText($row['user_id'] ?? null) . ':'
            . $this->scalarText($row['target_type'] ?? null) . ':'
            . $this->scalarText($row['target_id'] ?? null);
    }

    /** HR: Čita broj redaka iz manifesta. EN: Reads a dataset record count from the manifest. */
    private function datasetCount(BackupArchiveReader $reader, string $dataset): int
    {
        $manifest = $reader->providerManifest($this->id);
        if ($manifest === null) {
            return 0;
        }

        $datasets = $manifest['datasets'] ?? null;
        if (!is_array($datasets)) {
            return 0;
        }

        $definition = is_array($datasets[$dataset] ?? null) ? $datasets[$dataset] : [];

        return is_numeric($definition['records'] ?? null) ? max(0, (int)$definition['records']) : 0;
    }

    /** HR: Sigurno normalizira skalarnu vrijednost za složeni ključ. EN: Safely normalizes a scalar value for a compound key. */
    private function scalarText(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /** HR: Pretvara pozitivan broj ili dopuštenu praznu vrijednost. EN: Converts a positive number or an allowed empty value. */
    private function positiveInteger(mixed $value, bool $nullable = false): int
    {
        if ($nullable && ($value === null || $value === '')) {
            return 0;
        }

        if (!is_numeric($value) || (int)$value <= 0) {
            throw new BackupException('Expected a positive backup identifier.');
        }

        return (int)$value;
    }

    /** HR: Stvara RFC 4122 v4 UUID za kopirano praćenje. EN: Creates an RFC 4122 v4 UUID for a copied follow. */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
