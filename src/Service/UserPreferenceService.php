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

    /** HR: Koristi administratorovu site-wide postavku teme. EN: Uses the administrator's site-wide theme policy. */
    public const THEME_AUTO = 'auto';

    public const THEME_LIGHT = 'light';

    public const THEME_DARK = 'dark';

    /** HR: Izričito prati postavku svijetlo/tamno operacijskog sustava. EN: Explicitly follows the operating-system light/dark setting. */
    public const THEME_SYSTEM = 'system';

    /** @var list<string> */
    public const EMAIL_MODES = [
        self::EMAIL_OFF,
        self::EMAIL_IMMEDIATE,
        self::EMAIL_DAILY,
        self::EMAIL_IMPORTANT,
    ];

    /** @var list<string> */
    public const THEME_MODES = [
        self::THEME_AUTO,
        self::THEME_LIGHT,
        self::THEME_DARK,
        self::THEME_SYSTEM,
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
     * @return array{user_id:int,email_mode:string,notify_own_changes:bool,theme_mode:string,updated_at:?string}
     */
    public function forUser(int $userId): array
    {
        $defaults = [
            'user_id' => $userId,
            'email_mode' => self::EMAIL_OFF,
            'notify_own_changes' => false,
            'theme_mode' => self::THEME_AUTO,
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
            'theme_mode' => $this->themeMode($row['theme_mode'] ?? self::THEME_AUTO),
            'updated_at' => is_scalar($row['updated_at'] ?? null) ? (string)$row['updated_at'] : null,
        ];
    }

    /**
     * HR: Sprema pravila dostave bez dupliciranja retka korisnika.
     * EN: Saves delivery rules without duplicating the user's row.
     *
     * @return array{user_id:int,email_mode:string,notify_own_changes:bool,theme_mode:string,updated_at:?string}
     */
    public function save(
        int $userId,
        string $emailMode,
        bool $notifyOwnChanges,
        ?string $themeMode = null,
    ): array {
        if ($userId <= 0 || !$this->tableReady()) {
            throw new RuntimeException(__('Postavke praćenja trenutačno nisu dostupne.'));
        }

        $emailMode = $this->emailMode($emailMode);
        $now = date('Y-m-d H:i:s');
        $hasThemeMode = $this->database->schema()->hasColumn(
            ModuleSimbiozaUser::TABLE_PREFERENCES,
            'theme_mode',
        );
        $values = [
            'user_id' => $userId,
            'email_mode' => $emailMode,
            'notify_own_changes' => $notifyOwnChanges,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $updates = ['email_mode', 'notify_own_changes', 'updated_at'];
        if ($hasThemeMode) {
            $current = $themeMode ?? $this->forUser($userId)['theme_mode'];
            $values['theme_mode'] = $this->themeMode($current);
            $updates[] = 'theme_mode';
        }

        $this->database->table(ModuleSimbiozaUser::TABLE_PREFERENCES)->upsert(
            [$values],
            ['user_id'],
            $updates,
        );

        return $this->forUser($userId);
    }

    /**
     * HR: Sprema samo osobni način teme bez promjene postavki obavijesti.
     * EN: Saves only the personal theme mode without changing notification preferences.
     *
     * @return array{user_id:int,email_mode:string,notify_own_changes:bool,theme_mode:string,updated_at:?string}
     */
    public function saveThemeMode(int $userId, string $themeMode): array
    {
        if (
            !$this->tableReady()
            || !$this->database->schema()->hasColumn(ModuleSimbiozaUser::TABLE_PREFERENCES, 'theme_mode')
        ) {
            throw new RuntimeException(__('Postavke izgleda trenutačno nisu dostupne.'));
        }

        $current = $this->forUser($userId);

        return $this->save(
            $userId,
            $current['email_mode'],
            $current['notify_own_changes'],
            $themeMode,
        );
    }

    /** HR: Normalizira dopušteni način e-pošte. EN: Normalizes an allowed e-mail mode. */
    public function emailMode(mixed $value): string
    {
        $value = is_scalar($value) ? strtolower(trim((string)$value)) : '';

        return in_array($value, self::EMAIL_MODES, true) ? $value : self::EMAIL_OFF;
    }

    /** HR: Normalizira osobni način teme. EN: Normalizes the personal theme mode. */
    public function themeMode(mixed $value): string
    {
        $value = is_scalar($value) ? strtolower(trim((string)$value)) : '';

        return in_array($value, self::THEME_MODES, true) ? $value : self::THEME_AUTO;
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
            && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
