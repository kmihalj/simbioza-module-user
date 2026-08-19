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
 * HR: Usklađuje Calendar pretplate nastale prije ili izvan Simbioza modula s
 *     jedinstvenim popisom praćenja bez mijenjanja vlasničkih Calendar podataka.
 * EN: Reconciles Calendar subscriptions created before or outside the Simbioza
 *     module with the unified follow list without changing Calendar-owned data.
 */
final readonly class CalendarSubscriptionSynchronizer
{
    /** HR: Prima izvorne tablice i javni servis praćenja. EN: Receives source tables and the public follow service. */
    public function __construct(
        private Database $database,
        private FollowService $follows,
    ) {
    }

    /**
     * HR: Usklađuje sve kalendarske pretplate jednog korisnika, uključujući
     *     uklanjanje praćenja nakon prestanka pretplate.
     * EN: Reconciles all calendar subscriptions for one user, including follow
     *     removal after unsubscription.
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
             * HR: Pretplata može postojati nakon naknadnog gubitka ACL-a. Takav
             *     zastarjeli red ne smije stvoriti praćenje ni otkriti naziv.
             * EN: A subscription may remain after ACL access is later lost. Such
             *     a stale row must not create a follow or disclose its label.
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
                // HR: Nedostupna zastarjela pretplata ostaje preskočena.
                // EN: An inaccessible stale subscription stays skipped.
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
         * HR: Iznimka ima smisla samo dok izvorna pretplata postoji. Nakon
         *     odjave je uklanjamo pa nova buduća pretplata ponovno koristi
         *     očekivano automatsko praćenje.
         * EN: An exclusion is meaningful only while the source subscription
         *     exists. Remove it on unsubscribe so a future subscription again
         *     receives the expected automatic follow.
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
     * HR: Prije dostave jedne kalendarske promjene usklađuje sve njezine
     *     pretplatnike, pa postojeće pretplate rade i bez otvaranja profila.
     * EN: Before delivering one calendar change, reconciles all its subscribers
     *     so existing subscriptions work without opening the profile first.
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
     * HR: Vraća ID-eve kalendara na koje je korisnik pretplaćen neovisno o
     *     odluci želi li za njih primati obavijesti.
     * EN: Returns calendar IDs the user subscribes to independently of the
     *     decision whether notifications for them are followed.
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
     * HR: Vraća skup aktivnih Calendar pretplata korisnika.
     * EN: Returns the user's active Calendar-subscription set.
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
     * HR: Vraća skup kalendarskih praćenja korisnika.
     * EN: Returns the user's calendar-follow set.
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
     * HR: Vraća kalendarske iznimke korisnika radi čišćenja nakon odjave.
     * EN: Returns the user's calendar exclusions for cleanup after unsubscribe.
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
     * HR: Gradi upit koji poštuje noviji `is_subscribed`; stara Calendar shema
     *     tretira svaki follower red kao pretplatu.
     * EN: Builds a query honoring the newer `is_subscribed`; the legacy Calendar
     *     schema treats every follower row as a subscription.
     */
    private function calendarFollowerQuery(): QueryBuilder
    {
        $query = $this->database->table(ModuleCalendar::TABLE_CALENDAR_FOLLOWERS);
        if ($this->database->schema()->hasColumn(ModuleCalendar::TABLE_CALENDAR_FOLLOWERS, 'is_subscribed')) {
            $query->where('is_subscribed', '=', true);
        }

        return $query;
    }

    /** HR: Provjerava obje strane sinkronizacije prije upita. EN: Checks both sides of the reconciliation before querying. */
    private function ready(): bool
    {
        return $this->follows->tablesReady()
            && $this->database->schema()->hasTable(ModuleCalendar::TABLE_CALENDAR_FOLLOWERS);
    }
}
