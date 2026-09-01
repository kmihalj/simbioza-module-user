<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;

return new class implements ReversibleMigrationInterface {
    /** HR: Dodaje osobni izbor teme postojećim instalacijama. EN: Adds a personal theme choice to existing installations. */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if (
            $schema->hasTable(ModuleSimbiozaUser::TABLE_PREFERENCES)
            && !$schema->hasColumn(ModuleSimbiozaUser::TABLE_PREFERENCES, 'theme_mode')
        ) {
            $schema->table(
                ModuleSimbiozaUser::TABLE_PREFERENCES,
                static fn(Blueprint $table): mixed => $table->string('theme_mode', 16)
                    ->default('auto')
                    ->index(),
            );
        }
    }

    /** HR: Uklanja samo osobni izbor teme. EN: Removes only the personal theme choice. */
    public function down(Database $db): void
    {
        $schema = $db->schema();
        if (
            $schema->hasTable(ModuleSimbiozaUser::TABLE_PREFERENCES)
            && $schema->hasColumn(ModuleSimbiozaUser::TABLE_PREFERENCES, 'theme_mode')
        ) {
            $schema->table(
                ModuleSimbiozaUser::TABLE_PREFERENCES,
                static fn(Blueprint $table): mixed => $table->dropColumn('theme_mode'),
            );
        }
    }
};
