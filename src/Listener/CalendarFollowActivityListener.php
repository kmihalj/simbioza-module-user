<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Listener;

use AaiEduHr\HeartPhrameModuleCalendar\Event\CalendarEventChanged;
use AaiEduHr\SimbiozaModuleUser\Service\CalendarSubscriptionSynchronizer;
use AaiEduHr\SimbiozaModuleUser\Service\EmbeddedCalendarPageResolver;
use AaiEduHr\SimbiozaModuleUser\Service\FollowDeliveryService;
use AaiEduHr\SimbiozaModuleUser\Service\FollowTargetService;
use AaiEduHr\SimbiozaModuleUser\Value\FollowActivity;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

use function array_map;
use function implode;
use function in_array;
use function sprintf;
use function trim;

/** HR: Pretvara promjene kalendarskih događaja u obavijesti pratitelja. EN: Converts calendar-event changes into follower notifications. */
final readonly class CalendarFollowActivityListener
{
    /** HR: Prima jedinstveni servis dostave. EN: Receives the unified delivery service. */
    public function __construct(
        private FollowDeliveryService $delivery,
        private CalendarSubscriptionSynchronizer $subscriptions,
        private EmbeddedCalendarPageResolver $embeddedPages,
    ) {
    }

    /** HR: Otkazivanje i promjena termina označava kao važnu promjenu. EN: Marks cancellation and schedule changes as important. */
    public function __invoke(CalendarEventChanged $event): void
    {
        // HR: Uključuje i pretplate nastale prije instalacije modula.
        // EN: Includes subscriptions predating module installation.
        $this->subscriptions->syncCalendar($event->calendarId);
        $important = in_array($event->action, ['deleted', 'schedule_changed'], true);
        $message = $this->message($event);

        $pages = $this->embeddedPages->pagesForCalendar($event->calendarId);
        $dedupIdentity = 'calendar:' . $event->eventId . ':' . $event->action;
        if ($pages === []) {
            $this->delivery->process($this->activity($event, $message, $important, $dedupIdentity));

            return;
        }

        foreach ($pages as $page) {
            $this->delivery->process($this->activity(
                $event,
                $message,
                $important,
                $dedupIdentity,
                (int)$page['workspace_id'],
                (int)$page['id'],
                (string)$page['document_key'],
            ));
        }
    }

    /** HR: Gradi konkretan opis radnje, događaja i termina. EN: Builds a concrete action, event, and schedule description. */
    private function message(CalendarEventChanged $event): string
    {
        $title = trim($event->eventTitle) !== '' ? trim($event->eventTitle) : __('Događaj bez naslova');
        $currentPeriod = $this->period(
            $event->startsAt,
            $event->endsAt,
            $event->isAllDay,
            $event->timezone,
        );

        if ($event->action === 'created') {
            return sprintf(
                __('Dodan je događaj „%1$s”. Termin: %2$s.'),
                $title,
                $currentPeriod,
            );
        }

        if ($event->action === 'deleted') {
            return sprintf(
                __('Uklonjen je događaj „%1$s”. Termin je bio: %2$s.'),
                $title,
                $currentPeriod,
            );
        }

        if ($event->action === 'schedule_changed') {
            $previousPeriod = $this->period(
                $event->previousStartsAt ?? '',
                $event->previousEndsAt ?? '',
                $event->previousIsAllDay ?? $event->isAllDay,
                $event->previousTimezone ?? $event->timezone,
            );

            return $previousPeriod !== ''
                ? sprintf(
                    __('Promijenjen je termin događaja „%1$s”: %2$s → %3$s.'),
                    $title,
                    $previousPeriod,
                    $currentPeriod,
                )
                : sprintf(
                    __('Promijenjen je termin događaja „%1$s”. Novi termin: %2$s.'),
                    $title,
                    $currentPeriod,
                );
        }

        $changes = $this->changedFieldLabels($event->changedFields);

        return $changes !== ''
            ? sprintf(
                __('Ažuriran je događaj „%1$s” (%2$s). Termin: %3$s.'),
                $title,
                $changes,
                $currentPeriod,
            )
            : sprintf(
                __('Ažuriran je događaj „%1$s”. Termin: %2$s.'),
                $title,
                $currentPeriod,
            );
    }

    /**
     * HR: Lokalizira sažeti popis promijenjenih dijelova događaja.
     * EN: Localizes a concise list of changed event parts.
     *
     * @param list<string> $fields
     */
    private function changedFieldLabels(array $fields): string
    {
        $labels = [];
        foreach ($fields as $field) {
            $label = match ($field) {
                'title' => __('naslov'),
                'description' => __('opis'),
                'location' => __('lokacija'),
                'event_type_id' => __('vrsta događaja'),
                'recurrence_rule' => __('ponavljanje'),
                default => '',
            };
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return implode(', ', array_map(trim(...), array_unique($labels)));
    }

    /** HR: Formatira jednodnevni ili višednevni termin u aktivnom jeziku. EN: Formats a single-day or multi-day schedule in the active language. */
    private function period(string $startsAt, string $endsAt, bool $allDay, string $timezone): string
    {
        if (trim($startsAt) === '') {
            return '';
        }

        try {
            $zone = new DateTimeZone(trim($timezone) !== '' ? trim($timezone) : 'UTC');
            $starts = new DateTimeImmutable($startsAt, $zone);
            $ends = trim($endsAt) !== '' ? new DateTimeImmutable($endsAt, $zone) : $starts;
        } catch (Throwable) {
            return trim($startsAt . ($endsAt !== '' ? ' – ' . $endsAt : ''));
        }

        $dateFormat = __('calendar_notification_date_format');
        if ($dateFormat === 'calendar_notification_date_format') {
            $dateFormat = 'j. n. Y.';
        }

        if ($allDay) {
            $value = $starts->format($dateFormat);
            if ($starts->format('Y-m-d') !== $ends->format('Y-m-d')) {
                $value .= ' – ' . $ends->format($dateFormat);
            }

            return $value . ' (' . __('cijeli dan') . ')';
        }

        $timeFormat = __('calendar_notification_time_format');
        if ($timeFormat === 'calendar_notification_time_format') {
            $timeFormat = 'H:i';
        }

        $value = $starts->format($dateFormat . ' ' . $timeFormat);
        $value .= $starts->format('Y-m-d') === $ends->format('Y-m-d')
            ? '–' . $ends->format($timeFormat)
            : ' – ' . $ends->format($dateFormat . ' ' . $timeFormat);
        if (trim($timezone) !== '') {
            $value .= ' (' . trim($timezone) . ')';
        }

        return $value;
    }

    /** HR: Gradi jednu stranicom povezanu kalendarsku aktivnost. EN: Builds one page-related calendar activity. */
    private function activity(
        CalendarEventChanged $event,
        string $message,
        bool $important,
        string $dedupIdentity,
        ?int $workspaceId = null,
        ?int $pageId = null,
        ?string $documentId = null,
    ): FollowActivity {
        return new FollowActivity(
            eventKey: 'calendar.' . $event->action,
            targetType: FollowTargetService::TYPE_CALENDAR,
            targetId: (string)$event->calendarId,
            title: __('Promjena praćenog kalendara'),
            message: $message,
            actorUserId: $event->actorUserId,
            workspaceId: $workspaceId,
            pageId: $pageId,
            documentId: $documentId,
            importance: $important ? 'important' : 'normal',
            context: ['event_id' => $event->eventId, 'event_uid' => $event->eventUid],
            relatedTitle: __('Promjena kalendara na praćenoj stranici'),
            relatedMessage: __('Ugrađeni kalendar na praćenoj stranici je promijenjen.') . ' ' . $message,
            dedupIdentity: $dedupIdentity,
        );
    }
}
