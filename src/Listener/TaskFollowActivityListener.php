<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Listener;

use AaiEduHr\HeartPhrameModuleTask\Event\TaskChanged;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleUser\Service\FollowDeliveryService;
use AaiEduHr\SimbiozaModuleUser\Service\FollowTargetService;
use AaiEduHr\SimbiozaModuleUser\Value\FollowActivity;

use function is_array;
use function is_numeric;

/** HR: Povezuje promjenu zadatka s njegovom listom, stranicom i pratiteljima. EN: Connects a task change to its list, page, and followers. */
final readonly class TaskFollowActivityListener
{
    /** HR: Prima Workspace vezu i dostavu. EN: Receives the Workspace link and delivery. */
    public function __construct(
        private FollowDeliveryService $delivery,
        private WorkspaceRepository $workspaces,
    ) {
    }

    /** HR: Stvara važnu obavijest za završetak i promjenu nositelja. EN: Creates an important notification for completion and assignee changes. */
    public function __invoke(TaskChanged $event): void
    {
        $page = $this->workspaces->findNodeByDocumentKey($event->documentId);
        if (!is_array($page) || !is_numeric($page['id'] ?? null) || !is_numeric($page['workspace_id'] ?? null)) {
            return;
        }

        $important = in_array($event->action, ['completed', 'reopened', 'assignee_changed'], true);
        $this->delivery->process(new FollowActivity(
            eventKey: 'task.' . $event->action,
            targetType: FollowTargetService::TYPE_TASK_LIST,
            targetId: $event->listUuid,
            title: __('Promjena praćene liste zadataka'),
            message: $this->message($event->action, $event->taskLabel),
            actorUserId: $event->actorUserId,
            workspaceId: (int)$page['workspace_id'],
            pageId: (int)$page['id'],
            documentId: $event->documentId,
            importance: $important ? 'important' : 'normal',
            context: ['label_snapshot' => $event->listLabel],
            relatedTitle: __('Promjena liste zadataka na praćenoj stranici'),
            relatedMessage: __('Ugrađena lista zadataka na praćenoj stranici je promijenjena.')
                . ' ' . $this->message($event->action, $event->taskLabel),
            dedupIdentity: 'task:' . $event->taskUuid . ':' . $event->action,
        ));
    }

    /** HR: Vraća lokalizirani sažetak promjene. EN: Returns a localized change summary. */
    private function message(string $action, string $label): string
    {
        $verb = match ($action) {
            'completed' => __('Zadatak je dovršen'),
            'reopened' => __('Zadatak je ponovno otvoren'),
            'assignee_changed' => __('Promijenjen je nositelj zadatka'),
            'created' => __('Kreiran je novi zadatak'),
            default => __('Zadatak je promijenjen'),
        };

        return trim($label) !== '' ? $verb . ': ' . trim($label) : $verb . '.';
    }
}
