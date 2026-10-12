<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Notification;

use AaiEduHr\HeartPhrameModuleNotification\Contract\NotificationBatchVisibilityProviderInterface;
use AaiEduHr\SimbiozaModuleUser\Contract\FollowTargetVisibilityResolverInterface;
use AaiEduHr\SimbiozaModuleUser\Service\FollowTargetService;

use function is_array;
use function is_numeric;
use function is_scalar;
use function trim;

/**
 * EN: Re-applies the current Workspace/Calendar ACL whenever a notification
 *     created from a Simbioza follow is read.
 *
 * HR: Ponovno primjenjuje aktualni Workspace/Calendar ACL pri svakom čitanju
 *     obavijesti nastale iz praćenja u Simbiozi.
 */
final readonly class SimbiozaNotificationVisibilityProvider implements NotificationBatchVisibilityProviderInterface
{
    /**
     * EN: Receives the single safe target resolver.
     *
     * HR: Prima jedino mjesto za sigurno razrješavanje cilja.
     */
    public function __construct(private FollowTargetVisibilityResolverInterface $targets)
    {
    }

    /**
     * EN: Recognizes only notifications produced by the Simbioza User module.
     *
     * HR: Prepoznaje samo obavijesti koje je proizveo Simbioza User modul.
     */
    public function supports(array $notification): bool
    {
        return is_scalar($notification['source_module'] ?? null)
            && trim((string)$notification['source_module']) === 'simbioza-user';
    }

    /**
     * EN: Resolves the target again and returns the user's current ACL decision.
     *
     * HR: Ponovno razrješava cilj i vraća trenutačnu ACL odluku korisnika.
     */
    public function canView(int $userId, array $notification): bool
    {
        return $this->canViewBatch($userId, [$notification])[0];
    }

    /**
     * EN: Authorizes source and embedded destination together, with one bulk resolver call.
     * Both must be accessible; malformed targets and missing decisions fail closed.
     *
     * HR: Izvor i ugrađeno odredište provjerava zajedno, jednim skupnim pozivom resolvera.
     * Oba moraju biti dostupna; nevaljani ciljevi i nedostajuće odluke uskraćuju pristup.
     *
     * @param list<array<string,mixed>> $notifications
     * @return list<bool>
     */
    public function canViewBatch(int $userId, array $notifications): array
    {
        $targets = [];
        $positions = [];
        foreach ($notifications as $index => $notification) {
            $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];
            $type = is_scalar($data['target_type'] ?? null) ? trim((string)$data['target_type']) : '';
            $id = is_scalar($data['target_id'] ?? null) ? trim((string)$data['target_id']) : '';
            $positions[$index] = [];
            if ($type === '' || $id === '') {
                continue;
            }

            $positions[$index][] = count($targets);
            $targets[] = ['type' => $type, 'id' => $id, 'document_id' => $data['document_id'] ?? null];
            $pageId = is_numeric($data['page_id'] ?? null) ? (int)$data['page_id'] : 0;
            if ($pageId > 0 && ($type !== FollowTargetService::TYPE_PAGE || $id !== (string)$pageId)) {
                $positions[$index][] = count($targets);
                $targets[] = ['type' => FollowTargetService::TYPE_PAGE, 'id' => (string)$pageId];
            }
        }

        $decisions = $targets !== [] ? $this->targets->canViewTargets($userId, $targets) : [];
        $allowed = [];
        foreach ($positions as $indices) {
            $ok = $indices !== [];
            foreach ($indices as $position) {
                $ok = $ok && ($decisions[$position] ?? false) === true;
            }

            $allowed[] = $ok;
        }

        return $allowed;
    }
}
