<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleUser\Contract\FollowTargetResolverInterface;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use RuntimeException;

use function array_values;
use function date;
use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function random_bytes;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;

/**
 * HR: Sprema korisnička praćenja i vraća samo ACL-sigurne opise ciljeva.
 * EN: Stores user follows and returns only ACL-safe target descriptors.
 */
final readonly class FollowService
{
    /** HR: Prima bazu, ciljnu ACL rezoluciju i validator načina e-pošte. EN: Receives the database, target ACL resolution, and e-mail mode validator. */
    public function __construct(
        private Database $database,
        private FollowTargetResolverInterface $targets,
        private UserPreferenceService $preferences,
    ) {
    }

    /** HR: Provjerava jesu li tablice modula spremne. EN: Checks whether module tables are ready. */
    public function tablesReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleSimbiozaUser::TABLE_FOLLOWS)
            && $schema->hasTable(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)
            && $schema->hasTable(ModuleSimbiozaUser::TABLE_PREFERENCES)
            && $schema->hasTable(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES);
    }

    /**
     * HR: Uključuje praćenje tek nakon aktualne ACL provjere cilja.
     * EN: Enables following only after a current ACL check of the target.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function follow(
        int $userId,
        string $targetType,
        string $targetId,
        array $context = [],
        ?string $emailModeOverride = null,
    ): array {
        $saved = $this->storeFollow($userId, $targetType, $targetId, $context, $emailModeOverride);
        $this->clearAutomaticExclusion($userId, $targetType, $targetId);

        return $saved;
    }

    /**
     * HR: Uključuje praćenje nastalo iz drugog modula samo ako ga korisnik nije
     *     izričito isključio, bez brisanja te korisničke odluke.
     * EN: Enables a follow derived from another module only when the user has
     *     not explicitly opted out, without clearing that user decision.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    public function followAutomatically(
        int $userId,
        string $targetType,
        string $targetId,
        array $context = [],
        ?string $emailModeOverride = null,
    ): ?array {
        if ($this->isAutomaticFollowExcluded($userId, $targetType, $targetId)) {
            return null;
        }

        return $this->storeFollow($userId, $targetType, $targetId, $context, $emailModeOverride);
    }

    /**
     * HR: Sprema praćenje nakon ACL provjere; javne metode određuju smije li se
     *     pritom mijenjati iznimka automatskog praćenja.
     * EN: Stores a follow after ACL validation; public methods decide whether
     *     the automatic-follow exclusion may be changed as part of the action.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function storeFollow(
        int $userId,
        string $targetType,
        string $targetId,
        array $context = [],
        ?string $emailModeOverride = null,
    ): array {
        $this->assertReady();
        $targetType = strtolower(trim($targetType));
        $targetId = trim($targetId);
        if ($userId <= 0 || !in_array($targetType, FollowTargetService::TYPES, true) || $targetId === '') {
            throw new RuntimeException(__('Cilj praćenja nije valjan.'));
        }

        $descriptor = $this->targets->describe($targetType, $targetId, $userId, $context);
        if (!(bool)($descriptor['accessible'] ?? false)) {
            throw new RuntimeException(__('Nemate pravo pristupa sadržaju koji želite pratiti.'));
        }

        $override = $emailModeOverride === null || trim($emailModeOverride) === ''
            ? null
            : $this->preferences->emailMode($emailModeOverride);
        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)->upsert(
            [[
                'uuid' => $this->uuid(),
                'user_id' => $userId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'workspace_id' => $descriptor['workspace_id'],
                'page_id' => $descriptor['page_id'],
                'document_id' => $descriptor['document_id'],
                'label_snapshot' => $descriptor['label'],
                'email_mode_override' => $override,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', 'target_type', 'target_id'],
            [
                'workspace_id', 'page_id', 'document_id', 'label_snapshot',
                'email_mode_override', 'updated_at',
            ],
        );

        $row = $this->find($userId, $targetType, $targetId);
        if (!is_array($row)) {
            throw new RuntimeException(__('Spremljeno praćenje nije moguće učitati.'));
        }

        return $this->decorate($row);
    }

    /** HR: Isključuje samo vlastito praćenje korisnika. EN: Disables only the user's own follow. */
    public function unfollow(int $userId, string $targetType, string $targetId): bool
    {
        if ($userId <= 0 || !$this->tablesReady()) {
            return false;
        }

        return $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
            ->where('user_id', '=', $userId)
            ->where('target_type', '=', strtolower(trim($targetType)))
            ->where('target_id', '=', trim($targetId))
            ->delete() > 0;
    }

    /**
     * HR: Isključuje automatski izvedeno praćenje i pamti odluku dok izvorna
     *     pretplata postoji. Pretplatu ili vlasničke podatke drugog modula ne dira.
     * EN: Disables an automatically derived follow and remembers the choice while
     *     the source subscription exists. It does not change another module's data.
     */
    public function excludeAutomaticFollow(
        int $userId,
        string $targetType,
        string $targetId,
        string $source = 'automatic',
    ): bool {
        if ($userId <= 0 || !$this->tablesReady()) {
            return false;
        }

        $targetType = strtolower(trim($targetType));
        $targetId = trim($targetId);
        if (!in_array($targetType, FollowTargetService::TYPES, true) || $targetId === '') {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)->upsert(
            [[
                'user_id' => $userId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'source' => trim($source) !== '' ? trim($source) : 'automatic',
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', 'target_type', 'target_id'],
            ['source', 'updated_at'],
        );

        $this->unfollow($userId, $targetType, $targetId);

        return true;
    }

    /** HR: Briše zapamćenu iznimku bez uključivanja praćenja. EN: Clears a remembered exclusion without enabling a follow. */
    public function clearAutomaticExclusion(int $userId, string $targetType, string $targetId): bool
    {
        if ($userId <= 0 || !$this->tablesReady()) {
            return false;
        }

        return $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)
            ->where('user_id', '=', $userId)
            ->where('target_type', '=', strtolower(trim($targetType)))
            ->where('target_id', '=', trim($targetId))
            ->delete() > 0;
    }

    /** HR: Provjerava je li korisnik isključio automatsko praćenje cilja. EN: Checks whether the user opted out of an automatic follow. */
    public function isAutomaticFollowExcluded(int $userId, string $targetType, string $targetId): bool
    {
        if ($userId <= 0 || !$this->tablesReady()) {
            return false;
        }

        return is_array($this->database->table(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)
            ->where('user_id', '=', $userId)
            ->where('target_type', '=', strtolower(trim($targetType)))
            ->where('target_id', '=', trim($targetId))
            ->first());
    }

    /**
     * HR: Mijenja samo osobni način dostave postojećeg praćenja.
     * EN: Changes only the personal delivery mode of an existing follow.
     */
    public function setEmailMode(int $userId, string $targetType, string $targetId, string $mode): bool
    {
        if ($userId <= 0 || !$this->tablesReady()) {
            return false;
        }

        $mode = $this->preferences->emailMode($mode);

        $updated = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
            ->where('user_id', '=', $userId)
            ->where('target_type', '=', strtolower(trim($targetType)))
            ->where('target_id', '=', trim($targetId))
            ->update([
                'email_mode_override' => $mode,
                'updated_at' => date('Y-m-d H:i:s'),
            ]) > 0;

        return $updated || $this->isFollowing($userId, $targetType, $targetId);
    }

    /**
     * HR: Gradi ACL-siguran red za dostupni cilj koji korisnik trenutačno ne prati.
     * EN: Builds an ACL-safe row for an available target the user does not currently follow.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    public function availableTarget(int $userId, string $targetType, string $targetId, array $context = []): ?array
    {
        if ($userId <= 0 || !$this->tablesReady()) {
            return null;
        }

        $targetType = strtolower(trim($targetType));
        $targetId = trim($targetId);
        $descriptor = $this->targets->describe($targetType, $targetId, $userId, $context);
        if (!(bool)($descriptor['accessible'] ?? false)) {
            return null;
        }

        return [
            'uuid' => '',
            'user_id' => $userId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'workspace_id' => $descriptor['workspace_id'],
            'page_id' => $descriptor['page_id'],
            'document_id' => $descriptor['document_id'],
            'label_snapshot' => $descriptor['label'],
            'email_mode_override' => null,
            'created_at' => null,
            'updated_at' => null,
            'accessible' => true,
            'label' => $descriptor['label'],
            'url' => $descriptor['url'],
            'following' => false,
            'automatic_excluded' => $this->isAutomaticFollowExcluded($userId, $targetType, $targetId),
        ];
    }

    /** HR: Vraća slijedi li korisnik točno zadani cilj. EN: Returns whether the user follows the exact target. */
    public function isFollowing(int $userId, string $targetType, string $targetId): bool
    {
        return is_array($this->find($userId, $targetType, $targetId));
    }

    /**
     * HR: Vraća grupiran i pretraživ popis praćenja bez curenja ACL podataka.
     * EN: Returns a grouped, searchable follow list without leaking ACL data.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(int $userId, string $type = '', string $search = ''): array
    {
        if ($userId <= 0 || !$this->tablesReady()) {
            return [];
        }

        $query = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
            ->where('user_id', '=', $userId)
            ->orderBy('created_at', 'DESC');
        $type = strtolower(trim($type));
        if (in_array($type, FollowTargetService::TYPES, true)) {
            $query->where('target_type', '=', $type);
        }

        $items = [];
        foreach ($query->get() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = $this->decorate($this->stringKeys($row));
            if (
                trim($search) !== ''
                && !str_contains(
                    strtolower($this->text($item['label'] ?? null)),
                    strtolower(trim($search)),
                )
            ) {
                continue;
            }

            $items[] = [
                ...$item,
                'following' => true,
                'automatic_excluded' => false,
            ];
        }

        return $items;
    }

    /**
     * HR: Vraća jedinstvena praćenja na koja se odnosi domenski događaj.
     * EN: Returns unique follows affected by a domain event.
     *
     * @return list<array<string,mixed>>
     */
    public function matchingFollows(
        string $targetType,
        string $targetId,
        ?int $workspaceId = null,
        ?int $pageId = null,
    ): array {
        if (!$this->tablesReady()) {
            return [];
        }

        $rows = [];
        $this->appendRows($rows, $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->get());
        if ($workspaceId !== null && $workspaceId > 0) {
            $this->appendRows($rows, $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
                ->where('target_type', '=', FollowTargetService::TYPE_WORKSPACE)
                ->where('target_id', '=', (string)$workspaceId)
                ->get());
        }

        if ($pageId !== null && $pageId > 0 && $targetType !== FollowTargetService::TYPE_PAGE) {
            $this->appendRows($rows, $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
                ->where('target_type', '=', FollowTargetService::TYPE_PAGE)
                ->where('target_id', '=', (string)$pageId)
                ->get());
        }

        return array_values($rows);
    }

    /**
     * HR: Dohvaća jedno praćenje koje pripada zadanom korisniku i cilju.
     * EN: Retrieves one follow owned by the requested user and target.
     *
     * @return array<string,mixed>|null
     */
    private function find(int $userId, string $targetType, string $targetId): ?array
    {
        if ($userId <= 0 || !$this->tablesReady()) {
            return null;
        }

        $row = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
            ->where('user_id', '=', $userId)
            ->where('target_type', '=', strtolower(trim($targetType)))
            ->where('target_id', '=', trim($targetId))
            ->first();

        return is_array($row) ? $this->stringKeys($row) : null;
    }

    /**
     * HR: Dodaje aktualni ACL opis retku praćenja prije prikaza korisniku.
     * EN: Adds the current ACL descriptor to a follow row before presentation.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorate(array $row): array
    {
        $context = [
            'document_id' => $row['document_id'] ?? null,
            'label_snapshot' => $row['label_snapshot'] ?? null,
        ];
        $descriptor = $this->targets->describe(
            $this->text($row['target_type'] ?? null),
            $this->text($row['target_id'] ?? null),
            is_numeric($row['user_id'] ?? null) ? (int)$row['user_id'] : 0,
            $context,
        );

        return [
            ...$row,
            'accessible' => (bool)$descriptor['accessible'],
            'label' => $descriptor['label'],
            'url' => $descriptor['url'],
        ];
    }

    /**
     * HR: Spaja ORM retke po korisniku kako se preklapajuća praćenja ne bi dostavila dvaput.
     * EN: Merges ORM rows by user so overlapping follows are not delivered twice.
     *
     * @param array<int,array<string,mixed>> $indexed
     * @param-out array<int,array<string,mixed>> $indexed
     * @param iterable<mixed> $rows
     */
    private function appendRows(array &$indexed, iterable $rows): void
    {
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['user_id'] ?? null)) {
                continue;
            }

            $userId = (int)$row['user_id'];
            if ($userId > 0 && !isset($indexed[$userId])) {
                $indexed[$userId] = $this->stringKeys($row);
            }
        }
    }

    /** HR: Zaustavlja poslovnu radnju dok migracija nedostaje. EN: Stops the business operation while the migration is missing. */
    private function assertReady(): void
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException(__('Migracija modula Simbioza korisnik nije primijenjena.'));
        }
    }

    /**
     * HR: Filtrira ORM redak na tekstualne ključeve za stabilni povratni tip.
     * EN: Filters an ORM row to string keys for a stable return type.
     *
     * @param array<array-key,mixed> $row
     * @return array<string,mixed>
     */
    private function stringKeys(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** HR: Sigurno normalizira skalarni podatak retka. EN: Safely normalizes scalar row data. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** HR: Generira RFC 4122 UUID v4 bez dodatne biblioteke. EN: Generates an RFC 4122 UUID v4 without an extra library. */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
