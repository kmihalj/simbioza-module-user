<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Listener;

use AaiEduHr\HeartPhrameModuleCalendar\Event\CalendarFollowChanged;
use AaiEduHr\SimbiozaModuleUser\Service\CalendarSubscriptionSynchronizer;

/**
 * EN: Synchronizes an existing Calendar subscription with the unified follow list.
 *
 * HR: Sinkronizira postojeću Calendar pretplatu s jedinstvenim popisom praćenja.
 */
final readonly class CalendarFollowChangedListener
{
    /**
     * EN: Receives the synchronizer that honors a personal opt-out.
     *
     * HR: Prima sinkronizator koji poštuje osobni opt-out.
     */
    public function __construct(private CalendarSubscriptionSynchronizer $subscriptions)
    {
    }

    /**
     * EN: Reconciles the user after a subscription change.
     *
     * HR: Ponovno usklađuje korisnika nakon promjene pretplate.
     */
    public function __invoke(CalendarFollowChanged $event): void
    {
        $this->subscriptions->syncUser($event->userId);
    }
}
