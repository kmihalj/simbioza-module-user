<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Notification;

use AaiEduHr\HeartPhrameModuleNotification\Contract\NotificationVisibilityProviderInterface;
use AaiEduHr\SimbiozaModuleUser\Contract\FollowTargetResolverInterface;
use AaiEduHr\SimbiozaModuleUser\Service\FollowTargetService;

use function is_array;
use function is_numeric;
use function is_scalar;
use function trim;

/**
 * HR: Ponovno primjenjuje aktualni Workspace/Calendar ACL pri svakom čitanju
 *     obavijesti nastale iz praćenja u Simbiozi.
 * EN: Re-applies the current Workspace/Calendar ACL whenever a notification
 *     created from a Simbioza follow is read.
 */
final readonly class SimbiozaNotificationVisibilityProvider implements NotificationVisibilityProviderInterface
{
    /** HR: Prima jedino mjesto za sigurno razrješavanje cilja. EN: Receives the single safe target resolver. */
    public function __construct(private FollowTargetResolverInterface $targets)
    {
    }

    /**
     * HR: Prepoznaje samo obavijesti koje je proizveo Simbioza User modul.
     * EN: Recognizes only notifications produced by the Simbioza User module.
     */
    public function supports(array $notification): bool
    {
        return is_scalar($notification['source_module'] ?? null)
            && trim((string)$notification['source_module']) === 'simbioza-user';
    }

    /**
     * HR: Ponovno razrješava cilj i vraća trenutačnu ACL odluku korisnika.
     * EN: Resolves the target again and returns the user's current ACL decision.
     */
    public function canView(int $userId, array $notification): bool
    {
        $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];
        $type = is_scalar($data['target_type'] ?? null) ? trim((string)$data['target_type']) : '';
        $id = is_scalar($data['target_id'] ?? null) ? trim((string)$data['target_id']) : '';
        if ($type === '' || $id === '') {
            return false;
        }

        $descriptor = $this->targets->describe($type, $id, $userId, [
            'document_id' => $data['document_id'] ?? null,
        ]);

        if (!(bool)($descriptor['accessible'] ?? false)) {
            return false;
        }

        $pageId = is_numeric($data['page_id'] ?? null) ? (int)$data['page_id'] : 0;
        if ($pageId <= 0 || ($type === FollowTargetService::TYPE_PAGE && $id === (string)$pageId)) {
            return true;
        }

        /*
         * HR: Obavijest ugrađene komponente ostaje vidljiva samo dok korisnik
         *     smije vidjeti i izvor i stranicu na koju obavijest vodi.
         * EN: An embedded-component notification remains visible only while the
         *     user may access both the source and the page linked by the notice.
         */
        $page = $this->targets->describe(
            FollowTargetService::TYPE_PAGE,
            (string)$pageId,
            $userId,
            ['document_id' => $data['document_id'] ?? null],
        );

        return (bool)($page['accessible'] ?? false);
    }
}
