<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Event;

/**
 * EN: Publishes a business change to follows or personal preferences for auditing.
 *
 * HR: Objavljuje poslovnu promjenu praćenja ili osobnih postavki radi audita.
 */
final readonly class UserFollowChanged
{
    /**
     * EN: Stores the user, action, and optional target.
     *
     * HR: Sprema korisnika, radnju i opcionalni cilj.
     */
    public function __construct(
        public int $userId,
        public string $action,
        public ?string $targetType = null,
        public ?string $targetId = null,
    ) {
    }
}
