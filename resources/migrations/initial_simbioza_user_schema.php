<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Kreira prenosivu shemu osobnih postavki, praćenja i odgođenih
     *     dostava na SQLiteu, PostgreSQL-u i MySQL-u.
     * EN: Creates the portable personal-preference, follow, and deferred-delivery
     *     schema on SQLite, PostgreSQL, and MySQL.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();

        if (!$schema->hasTable(ModuleSimbiozaUser::TABLE_PREFERENCES)) {
            $schema->create(ModuleSimbiozaUser::TABLE_PREFERENCES, static function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('user_id')->unsigned()->unique();
                $table->string('email_mode', 24)->default('off')->index();
                $table->boolean('notify_own_changes')->default(false)->index();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable(ModuleSimbiozaUser::TABLE_FOLLOWS)) {
            $schema->create(ModuleSimbiozaUser::TABLE_FOLLOWS, static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->bigInteger('user_id')->unsigned()->index();
                $table->string('target_type', 24)->index();
                $table->string('target_id', 190)->index();
                $table->bigInteger('workspace_id')->unsigned()->nullable()->index();
                $table->bigInteger('page_id')->unsigned()->nullable()->index();
                $table->string('document_id', 190)->nullable()->index();
                $table->string('label_snapshot', 255)->nullable();
                $table->string('email_mode_override', 24)->nullable()->index();
                $table->timestamps();

                $table->unique(
                    ['user_id', 'target_type', 'target_id'],
                    'simbioza_user_follow_target_uq',
                );
                $table->index(
                    ['target_type', 'target_id', 'user_id'],
                    'simbioza_user_follow_subscribers_idx',
                );
            });
        }

        if (!$schema->hasTable(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)) {
            $schema->create(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS, static function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('user_id')->unsigned()->index();
                $table->string('target_type', 24)->index();
                $table->string('target_id', 190)->index();
                $table->string('source', 48)->default('automatic')->index();
                $table->timestamps();

                $table->unique(
                    ['user_id', 'target_type', 'target_id'],
                    'simbioza_user_follow_exclusion_target_uq',
                );
            });
        }

        if (!$schema->hasTable(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)) {
            $schema->create(
                ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES,
                static function (Blueprint $table): void {
                    $table->id();
                    $table->string('uuid', 36)->unique();
                    $table->bigInteger('user_id')->unsigned()->index();
                    $table->string('event_key', 128)->index();
                    $table->string('target_type', 24)->index();
                    $table->string('target_id', 190)->index();
                    $table->bigInteger('workspace_id')->unsigned()->nullable()->index();
                    $table->bigInteger('page_id')->unsigned()->nullable()->index();
                    $table->string('document_id', 190)->nullable()->index();
                    $table->bigInteger('actor_user_id')->unsigned()->nullable()->index();
                    $table->string('importance', 16)->default('normal')->index();
                    $table->string('title', 255);
                    $table->longText('message');
                    $table->string('link_url', 1024)->nullable();
                    $table->longText('payload_json')->nullable();
                    $table->string('dedup_key', 190)->index();
                    $table->integer('occurrence_count')->unsigned()->default(1);
                    $table->timestamp('deliver_after')->index();
                    $table->timestamp('delivered_at')->nullable()->index();
                    $table->timestamps();

                    $table->unique(
                        ['user_id', 'dedup_key', 'delivered_at'],
                        'simbioza_user_pending_dedup_uq',
                    );
                    $table->index(
                        ['user_id', 'delivered_at', 'deliver_after'],
                        'simbioza_user_pending_due_idx',
                    );
                },
            );
        }
    }

    /**
     * HR: Uklanja samo tablice u vlasništvu ovog modula, obrnutim redom.
     * EN: Removes only tables owned by this module, in reverse order.
     */
    public function down(Database $db): void
    {
        $db->schema()->dropIfExists(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES);
        $db->schema()->dropIfExists(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS);
        $db->schema()->dropIfExists(ModuleSimbiozaUser::TABLE_FOLLOWS);
        $db->schema()->dropIfExists(ModuleSimbiozaUser::TABLE_PREFERENCES);
    }
};
