<?php

/**
 * HR: Backup modul otkriva osobne postavke i praćenja kao dio cjeline Korisnici.
 * EN: Backup discovers personal preferences and follows as part of the Users group.
 */

declare(strict_types=1);

return ['providers' => [
    'heartphrame.backup.provider.simbioza-user',
    'heartphrame.backup.provider.simbioza-user-workspaces',
    'heartphrame.backup.provider.simbioza-user-workspace',
]];
