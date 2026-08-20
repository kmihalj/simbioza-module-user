<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser;

/**
 * HR: Sadrži stabilne identifikatore modula za praćenje korisničkog
 *     iskustva u Simbiozi.
 * EN: Contains stable identifiers for the module that manages the personal
 *     following experience in Simbioza.
 */
final class ModuleSimbiozaUser
{
    public const PACKAGE_NAME = 'aaieduhr/simbioza-module-user';

    public const TABLE_PREFERENCES = 'simbioza_user_preferences';

    public const TABLE_FOLLOWS = 'simbioza_user_follows';

    public const TABLE_FOLLOW_EXCLUSIONS = 'simbioza_user_follow_exclusions';

    public const TABLE_PENDING_DELIVERIES = 'simbioza_user_pending_deliveries';

    public const TABLE_SETTINGS = 'simbioza_user_settings';

    public const TABLE_PERSONAL_WORKSPACES = 'simbioza_user_personal_workspaces';

    public const TABLE_PERSONAL_WORKSPACE_POLICIES = 'simbioza_user_personal_workspace_policies';

    /** HR: Statički katalog nije moguće instancirati. EN: The static catalog cannot be instantiated. */
    private function __construct()
    {
    }
}
