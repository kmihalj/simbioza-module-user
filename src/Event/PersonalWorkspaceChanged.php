<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Event;

/**
 * EN: Publishes an administrative or automatic personal-space change.
 *
 * HR: Objavljuje administrativnu ili automatsku promjenu osobnog područja.
 */
final readonly class PersonalWorkspaceChanged
{
    /**
     * EN: Stores actor, owner, action, and optional Workspace.
     *
     * HR: Sprema izvršitelja, vlasnika, radnju i opcionalno područje.
     */
    public function __construct(
        public int $actorUserId,
        public int $ownerUserId,
        public string $action,
        public ?int $workspaceId = null,
    ) {
    }
}
