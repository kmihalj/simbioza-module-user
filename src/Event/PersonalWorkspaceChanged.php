<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Event;

/** HR: Objavljuje administrativnu ili automatsku promjenu osobnog područja. EN: Publishes an administrative or automatic personal-space change. */
final readonly class PersonalWorkspaceChanged
{
    /** HR: Sprema izvršitelja, vlasnika, radnju i opcionalno područje. EN: Stores actor, owner, action, and optional Workspace. */
    public function __construct(
        public int $actorUserId,
        public int $ownerUserId,
        public string $action,
        public ?int $workspaceId = null,
    ) {
    }
}
