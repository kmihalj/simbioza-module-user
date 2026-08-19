<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use RuntimeException;

use function date;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_scalar;
use function strtolower;
use function trim;

/**
 * HR: Upravlja zadanim osobnim pravilima dostave obavijesti praćenog sadržaja.
 * EN: Manages default personal delivery rules for followed-content notifications.
 */
final readonly class UserPreferenceService
{
    public const EMAIL_OFF = 'off';

    public const EMAIL_IMMEDIATE = 'immediate';

    public const EMAIL_DAILY = 'daily';

    public const EMAIL_IMPORTANT = 'important';

    /** @var list<string> */
    public const EMAIL_MODES = [
        self::EMAIL_OFF,
        self::EMAIL_IMMEDIATE,
        self::EMAIL_DAILY,
        self::EMAIL_IMPORTANT,
    ];

    /** HR: Prima ORM bazu radi prenosivih upita. EN: Receives the ORM database for portable queries. */
    public function __construct(private Database $database)
    {
    }

    /** HR: Provjerava je li migracija primijenjena. EN: Checks whether the migration is applied. */
    public function tableReady(): bool
    {
        return $this->database->schema()->hasTable(ModuleSimbiozaUser::TABLE_PREFERENCES);
    }

    /**
     * HR: Vraća spremljene ili sigurne zadane postavke korisnika.
     * EN: Returns stored or safe default user preferences.
     *
     * @return array{user_id:int,email_mode:string,notify_own_changes:bool,updated_at:?string}
     */
    public function forUser(int $userId): array
    {
        $defaults = [
            'user_id' => $userId,
            'email_mode' => self::EMAIL_OFF,
            'notify_own_changes' => false,
            'updated_at' => null,
        ];
        if ($userId <= 0 || !$this->tableReady()) {
            return $defaults;
        }

        $row = $this->database->table(ModuleSimbiozaUser::TABLE_PREFERENCES)
            ->where('user_id', '=', $userId)
            ->first();
        if (!is_array($row)) {
            return $defaults;
        }

        return [
            'user_id' => $userId,
            'email_mode' => $this->emailMode($row['email_mode'] ?? self::EMAIL_OFF),
            'notify_own_changes' => $this->boolValue($row['notify_own_changes'] ?? false),
            'updated_at' => is_scalar($row['updated_at'] ?? null) ? (string)$row['updated_at'] : null,
        ];
    }

    /**
     * HR: Sprema pravila dostave bez dupliciranja retka korisnika.
     * EN: Saves delivery rules without duplicating the user's row.
     *
     * @return array{user_id:int,email_mode:string,notify_own_changes:bool,updated_at:?string}
     */
    public function save(int $userId, string $emailMode, bool $notifyOwnChanges): array
    {
        if ($userId <= 0 || !$this->tableReady()) {
            throw new RuntimeException(__('Postavke praćenja trenutačno nisu dostupne.'));
        }

        $emailMode = $this->emailMode($emailMode);
        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleSimbiozaUser::TABLE_PREFERENCES)->upsert(
            [[
                'user_id' => $userId,
                'email_mode' => $emailMode,
                'notify_own_changes' => $notifyOwnChanges,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id'],
            ['email_mode', 'notify_own_changes', 'updated_at'],
        );

        return $this->forUser($userId);
    }

    /** HR: Normalizira dopušteni način e-pošte. EN: Normalizes an allowed e-mail mode. */
    public function emailMode(mixed $value): string
    {
        $value = is_scalar($value) ? strtolower(trim((string)$value)) : '';

        return in_array($value, self::EMAIL_MODES, true) ? $value : self::EMAIL_OFF;
    }

    /** HR: Normalizira boolean iz svih podržanih baza i formi. EN: Normalizes booleans from all supported databases and forms. */
    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value !== 0;
        }

        return is_scalar($value)
            && in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
