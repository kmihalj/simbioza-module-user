<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Listener;

use AaiEduHr\HeartPhrameModuleComment\Event\CommentChanged;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleUser\Service\FollowDeliveryService;
use AaiEduHr\SimbiozaModuleUser\Service\FollowTargetService;
use AaiEduHr\SimbiozaModuleUser\Value\FollowActivity;

use function is_array;
use function is_numeric;

/** HR: Povezuje komentare i odgovore s praćenom Workspace stranicom. EN: Connects comments and replies to a followed Workspace page. */
final readonly class CommentFollowActivityListener
{
    /** HR: Prima Workspace vezu i centralnu dostavu. EN: Receives the Workspace link and central delivery. */
    public function __construct(
        private FollowDeliveryService $delivery,
        private WorkspaceRepository $workspaces,
    ) {
    }

    /** HR: Preskače dokumente izvan područja i reakcije bez poslovne važnosti. EN: Skips documents outside workspaces and reactions without business significance. */
    public function __invoke(CommentChanged $event): void
    {
        if (!in_array($event->action, ['created', 'replied'], true)) {
            return;
        }

        $page = $this->workspaces->findNodeByDocumentKey($event->documentId);
        if (!is_array($page) || !is_numeric($page['id'] ?? null) || !is_numeric($page['workspace_id'] ?? null)) {
            return;
        }

        $isReply = $event->action === 'replied';
        $this->delivery->process(new FollowActivity(
            eventKey: $isReply ? 'comment.replied' : 'comment.created',
            targetType: FollowTargetService::TYPE_PAGE,
            targetId: (string)$page['id'],
            title: $isReply ? __('Novi odgovor na praćenoj stranici') : __('Novi komentar na praćenoj stranici'),
            message: $isReply ? __('Objavljen je novi odgovor na komentar.') : __('Objavljen je novi komentar.'),
            actorUserId: $event->actorUserId,
            workspaceId: (int)$page['workspace_id'],
            pageId: (int)$page['id'],
            documentId: $event->documentId,
            importance: $isReply ? 'important' : 'normal',
            context: ['language' => $event->language, 'comment_uuid' => $event->commentUuid],
        ));
    }
}
