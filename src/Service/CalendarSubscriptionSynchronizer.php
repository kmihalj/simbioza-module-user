<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\HeartPhrameModuleCalendar\ModuleCalendar;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\QueryBuilder;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use Throwable;

use function array_keys;
use function array_values;
use function is_array;
use function is_numeric;

/**
 * EN: Reconciles Calendar subscriptions created before or outside the Simbioza
 *     module with the unified follow list without changing Calendar-owned data.
 *
 * HR: Usklađuje Calendar pretplate nastale prije ili izvan Simbioza modula s
 *     jedinstvenim popisom praćenja bez mijenjanja vlasničkih Calendar podataka.
 */
final readonly class CalendarSubscriptionSynchronizer
{
    /**
     * EN: Receives source tables and the public follow service.
     *
     * HR: Prima izvorne tablice i javni servis praćenja.
     */
    public function __construct(
        private Database $database,
        private FollowService $follows,
    ) {
    }

    /**
     * EN: Reconciles all calendar subscriptions for one user, including follow
     *     removal after unsubscription.
     *
     * HR: Usklađuje sve kalendarske pretplate jednog korisnika, uključujući
     *     uklanjanje praćenja nakon prestanka pretplate.
     */
    public function syncUser(int $userId): int
    {
        if ($userId <= 0 || !$this->ready()) {
            return 0;
        }

        $subscribed = $this->subscribedCalendarIds($userId);
        $existing = $this->existingCalendarIds($userId);
        $changed = 0;
        foreach (array_keys($subscribed) as $calendarId) {
            if (isset($existing[$calendarId])) {
                continue;
            }

            /*
             * EN: A subscription may remain after ACL access is later lost. Such
             *     a stale row must not create a follow or disclose its label.
             *
             * HR: Pretplata može postojati nakon naknadnog gubitka ACL-a. Takav
             *     zastarjeli red ne smije stvoriti praćenje ni otkriti naziv.
             */
            try {
                if (
                    $this->follows->followAutomatically(
                        $userId,
                        FollowTargetService::TYPE_CALENDAR,
                        (string)$calendarId,
                    ) !== null
                ) {
                    ++$changed;
                }
            } catch (Throwable) {
                // EN: An inaccessible stale subscription stays skipped.
                //
                // HR: Nedostupna zastarjela pretplata ostaje preskočena.
            }
        }

        foreach (array_keys($existing) as $calendarId) {
            if (
                !isset($subscribed[$calendarId])
                && $this->follows->unfollow($userId, FollowTargetService::TYPE_CALENDAR, (string)$calendarId)
            ) {
                ++$changed;
            }
        }

        /*
         * EN: An exclusion is meaningful only while the source subscription
         *     exists. Remove it on unsubscribe so a future subscription again
         *     receives the expected automatic follow.
         *
         * HR: Iznimka ima smisla samo dok izvorna pretplata postoji. Nakon
         *     odjave je uklanjamo pa nova buduća pretplata ponovno koristi
         *     očekivano automatsko praćenje.
         */
        foreach (array_keys($this->excludedCalendarIds($userId)) as $calendarId) {
            if (!isset($subscribed[$calendarId])) {
                $this->follows->clearAutomaticExclusion(
                    $userId,
                    FollowTargetService::TYPE_CALENDAR,
                    (string)$calendarId,
                );
            }
        }

        return $changed;
    }

    /**
     * EN: Before delivering one calendar change, reconciles all its subscribers
     *     so existing subscriptions work without opening the profile first.
     *
     * HR: Prije dostave jedne kalendarske promjene usklađuje sve njezine
     *     pretplatnike, pa postojeće pretplate rade i bez otvaranja profila.
     */
    public function syncCalendar(int $calendarId): int
    {
        if ($calendarId <= 0 || !$this->ready()) {
            return 0;
        }

        $users = [];
        foreach ($this->calendarFollowerQuery()->where('calendar_id', '=', $calendarId)->get() as $row) {
            if (!is_array($row) || !is_numeric($row['user_id'] ?? null)) {
                continue;
            }

            $userId = (int)$row['user_id'];
            if ($userId > 0) {
                $users[$userId] = true;
            }
        }

        $existing = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
            ->where('target_type', '=', FollowTargetService::TYPE_CALENDAR)
            ->where('target_id', '=', (string)$calendarId)
            ->get();
        foreach ($existing as $row) {
            if (is_array($row) && is_numeric($row['user_id'] ?? null)) {
                $users[(int)$row['user_id']] = true;
            }
        }

        $excluded = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)
            ->where('target_type', '=', FollowTargetService::TYPE_CALENDAR)
            ->where('target_id', '=', (string)$calendarId)
            ->get();
        foreach ($excluded as $row) {
            if (is_array($row) && is_numeric($row['user_id'] ?? null)) {
                $users[(int)$row['user_id']] = true;
            }
        }

        $changed = 0;
        foreach (array_keys($users) as $userId) {
            $changed += $this->syncUser($userId);
        }

        return $changed;
    }

    /**
     * EN: Returns calendar IDs the user subscribes to independently of the
     *     decision whether notifications for them are followed.
     *
     * HR: Vraća ID-eve kalendara na koje je korisnik pretplaćen neovisno o
     *     odluci želi li za njih primati obavijesti.
     *
     * @return list<int>
     */
    public function subscribedCalendarIdsForUser(int $userId): array
    {
        return array_values(array_map(
            static fn(int|string $id): int => (int)$id,
            array_keys($this->subscribedCalendarIds($userId)),
        ));
    }

    /**
     * EN: Returns the user's active Calendar-subscription set.
     *
     * HR: Vraća skup aktivnih Calendar pretplata korisnika.
     *
     * @return array<int,true>
     */
    private function subscribedCalendarIds(int $userId): array
    {
        $ids = [];
        foreach ($this->calendarFollowerQuery()->where('user_id', '=', $userId)->get() as $row) {
            if (is_array($row) && is_numeric($row['calendar_id'] ?? null)) {
                $ids[(int)$row['calendar_id']] = true;
            }
        }

        return $ids;
    }

    /**
     * EN: Returns the user's calendar-follow set.
     *
     * HR: Vraća skup kalendarskih praćenja korisnika.
     *
     * @return array<int,true>
     */
    private function existingCalendarIds(int $userId): array
    {
        $ids = [];
        $rows = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
            ->where('user_id', '=', $userId)
            ->where('target_type', '=', FollowTargetService::TYPE_CALENDAR)
            ->get();
        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row['target_id'] ?? null)) {
                $ids[(int)$row['target_id']] = true;
            }
        }

        return $ids;
    }

    /**
     * EN: Returns the user's calendar exclusions for cleanup after unsubscribe.
     *
     * HR: Vraća kalendarske iznimke korisnika radi čišćenja nakon odjave.
     *
     * @return array<int,true>
     */
    private function excludedCalendarIds(int $userId): array
    {
        $ids = [];
        $rows = $this->database->table(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)
            ->where('user_id', '=', $userId)
            ->where('target_type', '=', FollowTargetService::TYPE_CALENDAR)
            ->get();
        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row['target_id'] ?? null)) {
                $ids[(int)$row['target_id']] = true;
            }
        }

        return $ids;
    }

    /**
     * EN: Selects explicitly subscribed rows from the current Calendar schema.
     *
     * HR: Odabire izričite pretplate iz aktualne Calendar sheme.
     */
    private function calendarFollowerQuery(): QueryBuilder
    {
        return $this->database->table(ModuleCalendar::TABLE_CALENDAR_FOLLOWERS)
            ->where('is_subscribed', '=', true);
    }

    /**
     * EN: Checks both sides of the reconciliation before querying.
     *
     * HR: Provjerava obje strane sinkronizacije prije upita.
     */
    private function ready(): bool
    {
        return $this->follows->tablesReady()
            && $this->database->schema()->hasTable(ModuleCalendar::TABLE_CALENDAR_FOLLOWERS);
    }
}
