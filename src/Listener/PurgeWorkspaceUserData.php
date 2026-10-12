<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Listener;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspacePermanentlyDeleting;

/**
 * EN: Removes follows, pending deliveries, and personal mapping for a deleted Workspace.
 *
 * HR: Uklanja praćenja, čekajuće dostave i osobno mapiranje obrisanog područja.
 */
final readonly class PurgeWorkspaceUserData
{
    /**
     * EN: Receives personal-settings storage.
     *
     * HR: Prima spremište osobnih postavki.
     */
    public function __construct(private Database $database)
    {
    }

    /**
     * EN: Cleans only user rows related to the supplied Workspace scope.
     *
     * HR: Čisti samo korisničke retke vezane za zadani Workspace opseg.
     */
    public function __invoke(WorkspacePermanentlyDeleting $event): void
    {
        $this->database->transaction(function (Database $database) use ($event): void {
            $database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)
                ->where('workspace_id', '=', $event->workspaceId)
                ->delete();
            $database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
                ->where('workspace_id', '=', $event->workspaceId)
                ->delete();

            if ($event->nodeIds !== []) {
                $database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)
                    ->whereIn('page_id', $event->nodeIds)
                    ->delete();
                $database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
                    ->whereIn('page_id', $event->nodeIds)
                    ->delete();
                $database->table(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)
                    ->where('target_type', '=', 'page')
                    ->whereIn('target_id', array_map(strval(...), $event->nodeIds))
                    ->delete();
            }

            if ($event->documentKeys !== []) {
                $database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)
                    ->whereIn('document_id', $event->documentKeys)
                    ->delete();
                $database->table(ModuleSimbiozaUser::TABLE_FOLLOWS)
                    ->whereIn('document_id', $event->documentKeys)
                    ->delete();
            }

            $database->table(ModuleSimbiozaUser::TABLE_FOLLOW_EXCLUSIONS)
                ->where('target_type', '=', 'workspace')
                ->where('target_id', '=', (string)$event->workspaceId)
                ->delete();
            $database->table(ModuleSimbiozaUser::TABLE_PERSONAL_WORKSPACES)
                ->where('workspace_id', '=', $event->workspaceId)
                ->delete();
        });
    }
}
