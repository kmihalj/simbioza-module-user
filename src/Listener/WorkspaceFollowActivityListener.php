<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Listener;

use AaiEduHr\HeartPhrameModuleWorkspace\Event\WorkspaceContentChanged;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleUser\Service\FollowDeliveryService;
use AaiEduHr\SimbiozaModuleUser\Service\FollowTargetService;
use AaiEduHr\SimbiozaModuleUser\Value\FollowActivity;

use function in_array;
use function is_array;
use function is_scalar;
use function trim;

/** HR: Pretvara neutralne Workspace promjene u događaje praćenja. EN: Converts neutral Workspace changes into follow activities. */
final readonly class WorkspaceFollowActivityListener
{
    /** HR: Prima dostavu i repozitorij samo radi sigurnog naziva. EN: Receives delivery and the repository only for a safe label. */
    public function __construct(
        private FollowDeliveryService $delivery,
        private WorkspaceRepository $workspaces,
    ) {
    }

    /** HR: Obrađuje promjene koje imaju korisničko značenje. EN: Processes changes that carry user-facing meaning. */
    public function __invoke(WorkspaceContentChanged $event): void
    {
        if (in_array($event->reason, ['workspace_created', 'unpublished_node_deleted'], true)) {
            return;
        }

        $page = $event->nodeId !== null ? $this->workspaces->findNodeById($event->nodeId) : null;
        $workspace = $this->workspaces->findWorkspaceById($event->workspaceId);
        $isPage = $event->nodeId !== null;
        $label = $isPage && is_array($page)
            ? $this->text($page['title'] ?? null)
            : (is_array($workspace) ? $this->text($workspace['name'] ?? null) : '');
        $action = $this->action($event->reason);
        $title = $isPage ? __('Promjena praćene stranice') : __('Promjena praćenog područja');
        $message = $label !== ''
            ? sprintf(__('%s: %s'), $action, $label)
            : $action;

        $this->delivery->process(new FollowActivity(
            eventKey: 'workspace.' . $event->reason,
            targetType: $isPage ? FollowTargetService::TYPE_PAGE : FollowTargetService::TYPE_WORKSPACE,
            targetId: (string)($event->nodeId ?? $event->workspaceId),
            title: $title,
            message: $message,
            actorUserId: $event->actorUserId,
            workspaceId: $event->workspaceId,
            pageId: $event->nodeId,
            documentId: is_array($page) ? $this->text($page['document_key'] ?? null) ?: null : null,
            importance: in_array(
                $event->reason,
                ['workspace_deleted', 'node_tree_deleted', 'publication_changed'],
                true,
            )
                ? 'important'
                : 'normal',
            context: ['language' => $event->language, 'reason' => $event->reason],
        ));
    }

    /** HR: Prevodi tehnički razlog u kratak korisnički opis. EN: Translates a technical reason into a concise user description. */
    private function action(string $reason): string
    {
        return match ($reason) {
            'publication_changed' => __('Objavljena je nova ili izmijenjena verzija'),
            'node_created' => __('Dodana je nova stranica'),
            'node_updated' => __('Promijenjena je stranica'),
            'node_tree_deleted' => __('Stranica je uklonjena'),
            'workspace_updated' => __('Promijenjene su postavke područja'),
            'workspace_deleted' => __('Područje je uklonjeno'),
            'workspace_restored' => __('Područje je vraćeno'),
            default => __('Sadržaj je promijenjen'),
        };
    }

    /** HR: Sigurno normalizira tekst iz ORM retka. EN: Safely normalizes text from an ORM row. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
