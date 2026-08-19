<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Event;

/**
 * HR: Objavljuje poslovnu promjenu praćenja ili osobnih postavki radi audita.
 * EN: Publishes a business change to follows or personal preferences for auditing.
 */
final readonly class UserFollowChanged
{
    /** HR: Sprema korisnika, radnju i opcionalni cilj. EN: Stores the user, action, and optional target. */
    public function __construct(
        public int $userId,
        public string $action,
        public ?string $targetType = null,
        public ?string $targetId = null,
    ) {
    }
}
