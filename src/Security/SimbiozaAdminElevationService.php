<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Security;

use AaiEduHr\HeartPhrameModuleAuth\Service\AuthAuditLogService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthGroupService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use HeartPhrame\Session\SessionInterface;

use function array_filter;
use function array_map;
use function array_values;
use function hash;
use function hash_equals;
use function is_array;
use function is_numeric;
use function is_scalar;
use function password_verify;
use function strtolower;
use function time;
use function trim;

/**
 * HR: Drži Simbioza-specifičnu privremenu administratorsku ovlast u sessionu.
 *     Stvarno članstvo ostaje u Authu, a ostatak aplikacije dobiva samo efektivna prava.
 * EN: Keeps Simbioza-specific temporary administrator elevation in the session.
 *     Real membership stays in Auth while the application sees only effective rights.
 */
final class SimbiozaAdminElevationService
{
    public const STATUS_ACTIVATED = 'activated';

    public const STATUS_INVALID_PASSWORD = 'invalid_password';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_NOT_ADMINISTRATOR = 'not_administrator';

    public const STATUS_MISSING_PASSWORD = 'missing_password';

    private const SESSION_KEY_ACTIVE = '_simbioza.admin_elevation';

    private const SESSION_KEY_FAILURES = '_simbioza.admin_elevation_failures';

    private const MAX_FAILURES = 5;

    private const FAILURE_WINDOW_SECONDS = 600;

    private const LOCK_SECONDS = 300;

    /** @var array<int,array<string,mixed>|null> */
    private array $users = [];

    /**
     * HR: Prima session, stvarni Auth korisnički servis i opcionalni audit zapisnik.
     * EN: Receives the session, real Auth user service, and optional audit log.
     */
    public function __construct(
        private readonly SessionInterface $session,
        private readonly AuthUserService $usersService,
        private readonly ?AuthAuditLogService $audit = null,
    ) {
    }

    /**
     * HR: Vraća stvarni Auth red aktivnog korisnika, uključujući grupe i lokalnu lozinku.
     * EN: Returns the active user's real Auth row, including groups and local password.
     *
     * @return array<string,mixed>|null
     */
    public function user(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        if (!array_key_exists($userId, $this->users)) {
            $this->users[$userId] = $this->usersService->findById($userId);
        }

        return $this->users[$userId];
    }

    /** HR: Stvarno administratorsko članstvo ostaje Authova trajna odluka. EN: Real administrator membership remains Auth's persistent decision. */
    public function isAdministratorMember(int $userId): bool
    {
        $user = $this->user($userId);

        return is_array($user) && (bool)($user['is_admin'] ?? false);
    }

    /** HR: Provjerava postoji li lokalna vjerodajnica za potvrdu ovlasti. EN: Checks whether a local credential exists for elevation. */
    public function hasLocalPassword(int $userId): bool
    {
        $user = $this->user($userId);
        $hash = is_array($user) && is_scalar($user['password_hash'] ?? null)
            ? trim((string)$user['password_hash'])
            : '';

        return $hash !== '';
    }

    /** HR: Stavka u profilu postoji samo administratoru s lokalnom lozinkom. EN: The profile entry exists only for an administrator with a local password. */
    public function canElevate(int $userId): bool
    {
        return $this->isAdministratorMember($userId) && $this->hasLocalPassword($userId);
    }

    /**
     * HR: Uspješna lokalna prijava već je potvrdila istu vjerodajnicu pa
     *     administratora možemo elevatirati bez ponovnog traženja lozinke.
     *     Privremena lozinka prvo se mora promijeniti.
     * EN: A successful local sign-in already verified the same credential, so
     *     an administrator can be elevated without another password prompt.
     *     A temporary password must be changed first.
     */
    public function activateAfterVerifiedLocalLogin(int $userId): bool
    {
        $user = $this->user($userId);
        if (
            !is_array($user)
            || !(bool)($user['is_admin'] ?? false)
            || (bool)($user['must_change_password'] ?? false)
        ) {
            return false;
        }

        $passwordHash = is_scalar($user['password_hash'] ?? null)
            ? trim((string)$user['password_hash'])
            : '';
        if ($passwordHash === '') {
            return false;
        }

        $this->session->set(self::SESSION_KEY_ACTIVE, [
            'user_id' => $userId,
            'password_fingerprint' => $this->passwordFingerprint($passwordHash),
            'activated_at' => time(),
        ]);
        $this->session->remove(self::SESSION_KEY_FAILURES);
        $this->audit('simbioza_admin_elevation_activated', $userId, ['source' => 'verified_local_login']);

        return true;
    }

    /** HR: Vraća je li valjana ovlast vezana uz trenutačnog korisnika i lozinku. EN: Returns whether valid elevation is bound to the current user and password. */
    public function isElevated(int $userId): bool
    {
        if (!$this->canElevate($userId)) {
            $this->session->remove(self::SESSION_KEY_ACTIVE);
            return false;
        }

        $state = $this->session->get(self::SESSION_KEY_ACTIVE);
        $user = $this->user($userId);
        $passwordHash = is_array($user) && is_scalar($user['password_hash'] ?? null)
            ? (string)$user['password_hash']
            : '';
        if (
            !is_array($state)
            || !is_numeric($state['user_id'] ?? null)
            || (int)$state['user_id'] !== $userId
            || !is_scalar($state['password_fingerprint'] ?? null)
            || !hash_equals((string)$state['password_fingerprint'], $this->passwordFingerprint($passwordHash))
        ) {
            $this->session->remove(self::SESSION_KEY_ACTIVE);
            return false;
        }

        return true;
    }

    /**
     * HR: Potvrđuje lokalnu lozinku bez uključivanja lokalne web-prijave.
     * EN: Confirms the local password independently of local web sign-in.
     */
    public function activate(int $userId, #[\SensitiveParameter] string $password): string
    {
        if (!$this->isAdministratorMember($userId)) {
            $this->audit('simbioza_admin_elevation_denied', $userId, ['reason' => 'not_administrator']);
            return self::STATUS_NOT_ADMINISTRATOR;
        }

        $user = $this->user($userId);
        $passwordHash = is_array($user) && is_scalar($user['password_hash'] ?? null)
            ? trim((string)$user['password_hash'])
            : '';
        if ($passwordHash === '') {
            $this->audit('simbioza_admin_elevation_denied', $userId, ['reason' => 'missing_password']);
            return self::STATUS_MISSING_PASSWORD;
        }

        if ($this->locked($userId)) {
            $this->audit('simbioza_admin_elevation_locked', $userId);
            return self::STATUS_LOCKED;
        }

        if ($password === '' || !password_verify($password, $passwordHash)) {
            $this->recordFailure($userId);
            $this->audit('simbioza_admin_elevation_failed', $userId, ['reason' => 'invalid_password']);
            return self::STATUS_INVALID_PASSWORD;
        }

        $this->session->set(self::SESSION_KEY_ACTIVE, [
            'user_id' => $userId,
            'password_fingerprint' => $this->passwordFingerprint($passwordHash),
            'activated_at' => time(),
        ]);
        $this->session->remove(self::SESSION_KEY_FAILURES);
        $this->session->regenerateId(true);
        $this->audit('simbioza_admin_elevation_activated', $userId);

        return self::STATUS_ACTIVATED;
    }

    /** HR: Odmah uklanja efektivne administratorske ovlasti. EN: Immediately removes effective administrator rights. */
    public function deactivate(int $userId): void
    {
        $wasElevated = $this->isElevated($userId);
        $this->clear(false);
        $this->session->regenerateId(true);
        if ($wasElevated) {
            $this->audit('simbioza_admin_elevation_deactivated', $userId);
        }
    }

    /** HR: Čisti Simbioza admin stanje pri prijavi i odjavi. EN: Clears Simbioza admin state on sign-in and sign-out. */
    public function clear(bool $regenerateSession = false): void
    {
        $this->session->remove(self::SESSION_KEY_ACTIVE);
        $this->session->remove(self::SESSION_KEY_FAILURES);
        if ($regenerateSession) {
            $this->session->regenerateId(true);
        }
    }

    /**
     * HR: Iz stvarnog Auth payload-a izrađuje efektivnog korisnika. Kada ovlast
     *     nije aktivna, uklanja i admin zastavicu i sistemsku admin grupu.
     * EN: Builds the effective user from the real Auth payload. When elevation
     *     is inactive, both the admin flag and system admin group are removed.
     *
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>|null
     */
    public function effectiveUser(?array $user): ?array
    {
        if (!is_array($user) || !is_numeric($user['id'] ?? null)) {
            return $user;
        }

        $userId = (int)$user['id'];
        $groups = $this->normalizeGroups($user['groups'] ?? null);
        $user['group_ids'] = $this->groupIds($groups);
        if ((bool)($user['is_admin'] ?? false) && $this->isElevated($userId)) {
            $user['admin_elevated'] = true;
            return $user;
        }

        $groups = array_values(array_filter(
            $groups,
            fn(mixed $group): bool => is_array($group) && $this->groupKey($group)
                !== AuthGroupService::SYSTEM_GROUP_ADMINISTRATOR_KEY,
        ));
        $user['groups'] = $groups;
        $user['group_ids'] = $this->groupIds($groups);
        $user['group_keys'] = array_values(array_filter(array_map(
            fn(mixed $group): string => is_array($group) ? $this->groupKey($group) : '',
            $groups,
        )));
        $user['is_admin'] = false;
        $user['admin_elevated'] = false;

        return $user;
    }

    /** HR: Session ograničenje usporava ponavljanje pogrešnih lozinki. EN: A session limit slows repeated invalid-password attempts. */
    private function locked(int $userId): bool
    {
        $failures = $this->failureState($userId);

        return $failures['locked_until'] > time();
    }

    /** HR: Bilježi neuspjeh i nakon pet pokušaja privremeno zaključava potvrdu. EN: Records failure and temporarily locks elevation after five attempts. */
    private function recordFailure(int $userId): void
    {
        $now = time();
        $failures = $this->failureState($userId);
        $startedAt = $failures['started_at'] > 0 ? $failures['started_at'] : $now;
        $count = $failures['count'] + 1;
        if ($startedAt + self::FAILURE_WINDOW_SECONDS < $now) {
            $startedAt = $now;
            $count = 1;
        }

        $this->session->set(self::SESSION_KEY_FAILURES, [
            'user_id' => $userId,
            'started_at' => $startedAt,
            'count' => $count,
            'locked_until' => $count >= self::MAX_FAILURES ? $now + self::LOCK_SECONDS : 0,
        ]);
    }

    /**
     * HR: Normalizira session stanje neuspjelih potvrda za zadanog korisnika.
     * EN: Normalizes failed-confirmation session state for the supplied user.
     *
     * @return array{user_id:int,started_at:int,count:int,locked_until:int}
     */
    private function failureState(int $userId): array
    {
        $state = $this->session->get(self::SESSION_KEY_FAILURES);
        $stateUserId = is_array($state) && is_numeric($state['user_id'] ?? null)
            ? (int)$state['user_id']
            : 0;
        if (!is_array($state) || $stateUserId !== $userId) {
            return ['user_id' => $userId, 'started_at' => 0, 'count' => 0, 'locked_until' => 0];
        }

        return [
            'user_id' => $stateUserId,
            'started_at' => is_numeric($state['started_at'] ?? null) ? (int)$state['started_at'] : 0,
            'count' => is_numeric($state['count'] ?? null) ? (int)$state['count'] : 0,
            'locked_until' => is_numeric($state['locked_until'] ?? null) ? (int)$state['locked_until'] : 0,
        ];
    }

    /**
     * HR: Iz normaliziranih grupa izdvaja valjane pozitivne ID-jeve.
     * EN: Extracts valid positive IDs from normalized groups.
     *
     * @param list<array<string,mixed>> $groups
     * @return list<int>
     */
    private function groupIds(array $groups): array
    {
        return array_values(array_filter(array_map(
            static fn(mixed $group): int => is_array($group) && is_numeric($group['id'] ?? null)
                ? (int)$group['id']
                : 0,
            $groups,
        ), static fn(int $id): bool => $id > 0));
    }

    /**
     * HR: Pretvara proizvoljan auth podatak u listu grupa sa string ključevima.
     * EN: Converts arbitrary auth data into a list of groups with string keys.
     *
     * @return list<array<string,mixed>>
     */
    private function normalizeGroups(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $groups = [];
        foreach ($value as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $group = [];
            foreach ($candidate as $key => $item) {
                if (is_string($key)) {
                    $group[$key] = $item;
                }
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * HR: Vraća normalizirani stabilni ključ grupe.
     * EN: Returns the normalized stable group key.
     *
     * @param array<string,mixed> $group
     */
    private function groupKey(array $group): string
    {
        $key = $group['group_key'] ?? $group['key'] ?? '';

        return is_scalar($key) ? strtolower(trim((string)$key)) : '';
    }

    /** HR: Veže elevaciju uz aktualni hash lokalne lozinke. EN: Binds elevation to the current local-password hash. */
    private function passwordFingerprint(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    /**
     * HR: Zapisuje sigurnosni događaj kada je Auth audit servis dostupan.
     * EN: Records a security event when the Auth audit service is available.
     *
     * @param array<string,mixed> $context
     */
    private function audit(string $event, int $userId, array $context = []): void
    {
        $this->audit?->logEvent($event, $userId > 0 ? $userId : null, $userId > 0 ? $userId : null, $context);
    }
}
