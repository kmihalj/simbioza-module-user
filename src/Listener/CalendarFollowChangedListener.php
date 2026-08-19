<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Listener;

use AaiEduHr\HeartPhrameModuleCalendar\Event\CalendarFollowChanged;
use AaiEduHr\SimbiozaModuleUser\Service\CalendarSubscriptionSynchronizer;

/** HR: Sinkronizira postojeću Calendar pretplatu s jedinstvenim popisom praćenja. EN: Synchronizes an existing Calendar subscription with the unified follow list. */
final readonly class CalendarFollowChangedListener
{
    /** HR: Prima sinkronizator koji poštuje osobni opt-out. EN: Receives the synchronizer that honors a personal opt-out. */
    public function __construct(private CalendarSubscriptionSynchronizer $subscriptions)
    {
    }

    /** HR: Ponovno usklađuje korisnika nakon promjene pretplate. EN: Reconciles the user after a subscription change. */
    public function __invoke(CalendarFollowChanged $event): void
    {
        $this->subscriptions->syncUser($event->userId);
    }
}
