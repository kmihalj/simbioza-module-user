<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Dodaje postavke, stabilno mapiranje i korisničke iznimke osobnih područja.
     * EN: Adds settings, stable mappings, and per-user exceptions for personal Workspaces.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();

        if (!$schema->hasTable(ModuleSimbiozaUser::TABLE_SETTINGS)) {
            $schema->create(ModuleSimbiozaUser::TABLE_SETTINGS, static function (Blueprint $table): void {
                $table->id();
                $table->string('setting_key', 96)->unique();
                $table->string('setting_value', 255);
                $table->timestamps();
            });
        }

        if (!$schema->hasTable(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES)) {
            $schema->create(
                ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->bigInteger('user_id')->unsigned()->unique();
                    $table->bigInteger('workspace_id')->unsigned()->unique();
                    $table->boolean('created_automatically')->default(true);
                    $table->timestamps();
                },
            );
        }

        if (!$schema->hasTable(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACE_POLICIES)) {
            $schema->create(
                ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACE_POLICIES,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->bigInteger('user_id')->unsigned()->unique();
                    $table->boolean('auto_create_enabled')->default(true);
                    $table->bigInteger('updated_by_user_id')->unsigned()->nullable();
                    $table->timestamps();
                },
            );
        }
    }

    /** HR: Uklanja samo tablice dodane ovom nadogradnjom. EN: Removes only tables added by this upgrade. */
    public function down(Database $db): void
    {
        $db->schema()->dropIfExists(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACE_POLICIES);
        $db->schema()->dropIfExists(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES);
        $db->schema()->dropIfExists(ModuleSimbiozaUser::TABLE_SETTINGS);
    }
};
