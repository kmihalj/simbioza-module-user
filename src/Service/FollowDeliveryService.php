<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationPreferenceService;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleUser\Contract\FollowTargetResolverInterface;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use AaiEduHr\SimbiozaModuleUser\Value\FollowActivity;
use DateTimeImmutable;
use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function date;
use function is_array;
use function is_numeric;
use function is_object;
use function is_scalar;
use function json_decode;
use function json_encode;
use function method_exists;
use function random_bytes;
use function sprintf;
use function strtolower;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * HR: Koordinira ACL, deduplikaciju, in-app zapis te neposrednu ili sažetu
 *     e-mail dostavu za sve vrste praćenja.
 * EN: Coordinates ACL, deduplication, in-app storage, and immediate or digest
 *     e-mail delivery for every follow type.
 */
final readonly class FollowDeliveryService
{
    private const SOURCE_MODULE = 'simbioza-user';

    private const EMAIL_SERVICE = \AaiEduHr\HeartPhrameModuleEmail\Service\EmailService::class;

    /**
     * HR: Prima generičke kanale i servise specifične za Simbioza pravila.
     * EN: Receives generic channels and services specific to Simbioza rules.
     */
    public function __construct(
        private Database $database,
        private FollowService $follows,
        private FollowTargetResolverInterface $targets,
        private UserPreferenceService $preferences,
        private NotificationPreferenceService $notificationPreferences,
        private NotificationService $notifications,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * HR: Pretvara jedan domenski događaj u najviše jednu obavijest po korisniku.
     * EN: Turns one domain event into at most one notification per user.
     */
    public function process(FollowActivity $activity): int
    {
        if (!$this->follows->tablesReady()) {
            return 0;
        }

        $matched = $this->follows->matchingFollows(
            $activity->targetType,
            $activity->targetId,
            $activity->workspaceId,
            $activity->pageId,
        );
        $delivered = 0;
        foreach ($matched as $follow) {
            $userId = is_numeric($follow['user_id'] ?? null) ? (int)$follow['user_id'] : 0;
            if ($userId <= 0) {
                continue;
            }

            $preference = $this->preferences->forUser($userId);
            if (
                $activity->actorUserId !== null
                && $activity->actorUserId === $userId
                && !(bool)$preference['notify_own_changes']
            ) {
                continue;
            }

            /*
             * HR: Cilj se ponovno razrješava za primatelja. Snapshot naziva iz
             *     tablice praćenja nikada se ne koristi kada ACL više ne vrijedi.
             * EN: Resolve the target again for the recipient. The label snapshot
             *     from the follow table is never used after ACL access is lost.
             */
            $descriptor = $this->targets->describe(
                $activity->targetType,
                $activity->targetId,
                $userId,
                [
                    ...$activity->context,
                    'document_id' => $activity->documentId,
                    'label_snapshot' => $activity->context['label_snapshot']
                        ?? $follow['label_snapshot']
                        ?? null,
                ],
            );
            if (!(bool)($descriptor['accessible'] ?? false)) {
                continue;
            }

            $followType = $this->text($follow['target_type'] ?? null);
            $followId = $this->text($follow['target_id'] ?? null);
            $relatedFollow = $followType !== ''
                && $followId !== ''
                && ($followType !== $activity->targetType || $followId !== $activity->targetId);
            if ($relatedFollow) {
                /*
                 * HR: Pratitelj stranice ili područja mora proći i ACL izvornog
                 *     kalendara/zadatka i ACL povezane stranice. Time ugrađena
                 *     promjena ne može otkriti sadržaj nakon gubitka prava.
                 * EN: A page or workspace follower must pass both the source
                 *     calendar/task ACL and the related page ACL. This prevents
                 *     an embedded change from leaking content after access loss.
                 */
                $followDescriptor = $this->targets->describe(
                    $followType,
                    $followId,
                    $userId,
                    [
                        'document_id' => $follow['document_id'] ?? $activity->documentId,
                        'label_snapshot' => $follow['label_snapshot'] ?? null,
                    ],
                );
                if (!(bool)($followDescriptor['accessible'] ?? false)) {
                    continue;
                }

                $descriptor = $followDescriptor;
                if ($activity->pageId !== null && $activity->pageId > 0) {
                    $pageDescriptor = $this->targets->describe(
                        FollowTargetService::TYPE_PAGE,
                        (string)$activity->pageId,
                        $userId,
                        ['document_id' => $activity->documentId],
                    );
                    if (!(bool)($pageDescriptor['accessible'] ?? false)) {
                        continue;
                    }

                    $descriptor = $pageDescriptor;
                }
            }

            $effectiveActivity = $relatedFollow
                ? new FollowActivity(
                    eventKey: $activity->eventKey,
                    targetType: $activity->targetType,
                    targetId: $activity->targetId,
                    title: $activity->relatedTitle ?? $activity->title,
                    message: $activity->relatedMessage ?? $activity->message,
                    actorUserId: $activity->actorUserId,
                    workspaceId: $activity->workspaceId,
                    pageId: $activity->pageId,
                    documentId: $activity->documentId,
                    importance: $activity->importance,
                    context: $activity->context,
                    relatedTitle: $activity->relatedTitle,
                    relatedMessage: $activity->relatedMessage,
                    dedupIdentity: $activity->dedupIdentity,
                )
                : $activity;

            /*
             * HR: Za e-poštu povezane promjene spremamo stranicu kao konačni
             *     cilj dostave, a izvorni kalendar/listu u kontekst. Dnevni
             *     worker tako može ponovno provjeriti oba ACL-a i zadržati
             *     poveznicu na stranicu.
             * EN: For related e-mail changes, store the page as the final
             *     delivery target and the source calendar/list in context. The
             *     daily worker can then re-check both ACLs and keep the page URL.
             */
            $emailActivity = $effectiveActivity;
            if ($relatedFollow && $activity->pageId !== null && $activity->pageId > 0) {
                $emailActivity = new FollowActivity(
                    eventKey: $effectiveActivity->eventKey,
                    targetType: FollowTargetService::TYPE_PAGE,
                    targetId: (string)$activity->pageId,
                    title: $effectiveActivity->title,
                    message: $effectiveActivity->message,
                    actorUserId: $effectiveActivity->actorUserId,
                    workspaceId: $effectiveActivity->workspaceId,
                    pageId: $effectiveActivity->pageId,
                    documentId: $effectiveActivity->documentId,
                    importance: $effectiveActivity->importance,
                    context: [
                        ...$effectiveActivity->context,
                        'source_target_type' => $activity->targetType,
                        'source_target_id' => $activity->targetId,
                        'source_document_id' => $activity->documentId,
                    ],
                    relatedTitle: $effectiveActivity->relatedTitle,
                    relatedMessage: $effectiveActivity->relatedMessage,
                    dedupIdentity: $effectiveActivity->dedupIdentity,
                );
            }

            $dedupKey = $this->dedupKey($effectiveActivity, $userId);
            $this->notifications->notifyUser(
                $userId,
                'simbioza.follow.' . $effectiveActivity->eventKey,
                $effectiveActivity->title,
                $effectiveActivity->message,
                (string)($descriptor['url'] ?? ''),
                self::SOURCE_MODULE,
                $activity->targetType . ':' . $activity->targetId,
                $dedupKey,
                [
                    'target_type' => $activity->targetType,
                    'target_id' => $activity->targetId,
                    'workspace_id' => $activity->workspaceId,
                    'page_id' => $activity->pageId,
                    'document_id' => $activity->documentId,
                    'importance' => $activity->importance,
                ],
                false,
            );

            $mode = is_scalar($follow['email_mode_override'] ?? null)
                && trim((string)$follow['email_mode_override']) !== ''
                ? $this->preferences->emailMode($follow['email_mode_override'])
                : (string)$preference['email_mode'];
            $this->routeEmail($userId, $mode, $emailActivity, $descriptor, $dedupKey);
            ++$delivered;
        }

        return $delivered;
    }

    /**
     * HR: Šalje dospjele dnevne sažetke nakon zadnje ACL provjere svake stavke.
     * EN: Sends due daily digests after a final ACL check of every item.
     */
    public function dispatchDueDigests(int $limit = 500): int
    {
        if (!$this->follows->tablesReady()) {
            return 0;
        }

        $rows = $this->database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)
            ->whereNull('delivered_at')
            ->where('deliver_after', '<=', date('Y-m-d H:i:s'))
            ->orderBy('user_id', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->limit(max(1, min(5000, $limit)))
            ->get();
        $byUser = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['user_id'] ?? null)) {
                continue;
            }

            $byUser[(int)$row['user_id']][] = $this->stringKeys($row);
        }

        $sent = 0;
        foreach ($byUser as $userId => $items) {
            if (!$this->notificationPreferences->emailEnabled($userId)) {
                $this->markDelivered($items);
                continue;
            }

            $visible = [];
            foreach ($items as $item) {
                $context = $this->decodePayload($item['payload_json'] ?? null);
                $descriptor = $this->targets->describe(
                    $this->text($item['target_type'] ?? null),
                    $this->text($item['target_id'] ?? null),
                    $userId,
                    [
                        ...$context,
                        'document_id' => $item['document_id'] ?? null,
                    ],
                );
                if (!(bool)($descriptor['accessible'] ?? false)) {
                    continue;
                }

                $sourceType = $this->text($context['source_target_type'] ?? null);
                $sourceId = $this->text($context['source_target_id'] ?? null);
                if ($sourceType !== '' && $sourceId !== '') {
                    $sourceDescriptor = $this->targets->describe(
                        $sourceType,
                        $sourceId,
                        $userId,
                        [
                            ...$context,
                            'document_id' => $context['source_document_id'] ?? null,
                        ],
                    );
                    if (!(bool)($sourceDescriptor['accessible'] ?? false)) {
                        continue;
                    }
                }

                $visible[] = [
                    ...$item,
                    'url' => $descriptor['url'],
                ];
            }

            $deliveryCompleted = $visible === [];
            if ($visible !== []) {
                $lines = [];
                foreach ($visible as $item) {
                    $count = is_numeric($item['occurrence_count'] ?? null)
                        ? max(1, (int)$item['occurrence_count'])
                        : 1;
                    $suffix = $count > 1 ? sprintf(__(' (%d promjena)'), $count) : '';
                    $title = $this->text($item['title'] ?? null) ?: __('Promjena');
                    $message = $this->text($item['message'] ?? null);
                    $url = $this->text($item['url'] ?? null);
                    $lines[] = '- ' . $title . $suffix
                        . "\n  " . $message
                        . ($url !== '' ? "\n  " . $url : '');
                }

                if (
                    $this->queueEmail(
                        $userId,
                        __('Dnevni sažetak praćenih promjena'),
                        implode("\n\n", $lines),
                        '',
                        'simbioza-user-digest:' . date('Y-m-d') . ':' . $userId,
                    )
                ) {
                    ++$sent;
                    $deliveryCompleted = true;
                }
            }

            /*
             * HR: Nedostupan ili privremeno neispravan E-mail modul ne smije
             *     nepovratno označiti sažetak poslanim; sljedeći worker ga ponavlja.
             * EN: A missing or temporarily failing E-mail module must not mark
             *     a digest as delivered irreversibly; the next worker retries it.
             */
            if ($deliveryCompleted) {
                $this->markDelivered($items);
            }
        }

        return $sent;
    }

    /**
     * HR: Usmjerava dopuštenu promjenu u neposrednu e-poštu, dnevni sažetak
     *     ili samo obavijest u aplikaciji prema osobnim postavkama.
     * EN: Routes an allowed change to immediate e-mail, a daily digest, or
     *     in-app notification only, according to personal preferences.
     *
     * @param array<string,mixed> $descriptor
     */
    private function routeEmail(
        int $userId,
        string $mode,
        FollowActivity $activity,
        array $descriptor,
        string $dedupKey,
    ): void {
        if (
            $mode === UserPreferenceService::EMAIL_OFF
            || !$this->notificationPreferences->emailEnabled($userId)
            || !class_exists(self::EMAIL_SERVICE)
        ) {
            return;
        }

        if ($mode === UserPreferenceService::EMAIL_DAILY) {
            $this->queueDigestItem($userId, $activity, $descriptor, $dedupKey);

            return;
        }

        if (
            $mode === UserPreferenceService::EMAIL_IMPORTANT
            && strtolower($activity->importance) !== 'important'
        ) {
            return;
        }

        $this->queueEmail(
            $userId,
            $activity->title,
            $activity->message,
            $this->text($descriptor['url'] ?? null),
            'simbioza-user:' . $dedupKey,
        );
    }

    /**
     * HR: Dodaje ili objedinjuje jednu promjenu u redu dnevnog sažetka.
     * EN: Adds or coalesces one change in the daily-digest queue.
     *
     * @param array<string,mixed> $descriptor
     */
    private function queueDigestItem(
        int $userId,
        FollowActivity $activity,
        array $descriptor,
        string $dedupKey,
    ): void {
        $existing = $this->database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)
            ->where('user_id', '=', $userId)
            ->where('dedup_key', '=', $dedupKey)
            ->whereNull('delivered_at')
            ->first();
        $now = date('Y-m-d H:i:s');
        if (is_array($existing) && is_numeric($existing['id'] ?? null)) {
            $count = is_numeric($existing['occurrence_count'] ?? null)
                ? (int)$existing['occurrence_count'] + 1
                : 2;
            $this->database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)
                ->where('id', '=', (int)$existing['id'])
                ->update([
                    'title' => $activity->title,
                    'message' => $activity->message,
                    'link_url' => $descriptor['url'] ?? null,
                    'occurrence_count' => $count,
                    'updated_at' => $now,
                ]);

            return;
        }

        $deliverAfter = (new DateTimeImmutable('tomorrow 08:00:00'))->format('Y-m-d H:i:s');
        $this->database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)->insert([
            'uuid' => $this->uuid(),
            'user_id' => $userId,
            'event_key' => $activity->eventKey,
            'target_type' => $activity->targetType,
            'target_id' => $activity->targetId,
            'workspace_id' => $activity->workspaceId,
            'page_id' => $activity->pageId,
            'document_id' => $activity->documentId,
            'actor_user_id' => $activity->actorUserId,
            'importance' => $activity->importance,
            'title' => $activity->title,
            'message' => $activity->message,
            'link_url' => $descriptor['url'] ?? null,
            'payload_json' => $activity->context === [] ? null : $this->encodePayload($activity->context),
            'dedup_key' => $dedupKey,
            'occurrence_count' => 1,
            'deliver_after' => $deliverAfter,
            'delivered_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * HR: Sigurno predaje poruku opcionalnom Email modulu i bilježi tehničku
     *     pogrešku bez prekidanja obavijesti u aplikaciji.
     * EN: Safely hands a message to the optional Email module and logs a
     *     technical failure without interrupting the in-app notification.
     */
    private function queueEmail(
        int $userId,
        string $subject,
        string $message,
        string $link,
        string $dedupKey,
    ): bool {
        if (!class_exists(self::EMAIL_SERVICE)) {
            return false;
        }

        try {
            $email = $this->container->get(self::EMAIL_SERVICE);
            if (!is_object($email) || !method_exists($email, 'queueForUser')) {
                return false;
            }

            $email->queueForUser($userId, $subject, $message, null, $dedupKey, $link);

            return true;
        } catch (Throwable $throwable) {
            $this->logger->error('Simbioza followed-content e-mail queue failed.', [
                'module' => self::SOURCE_MODULE,
                'recipient_user_id' => $userId,
                'exception' => $throwable,
            ]);

            return false;
        }
    }

    /**
     * HR: Označava uspješno obrađene redove sažetka dostavljenima.
     * EN: Marks successfully processed digest rows as delivered.
     *
     * @param iterable<array<string,mixed>> $items
     */
    private function markDelivered(iterable $items): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($items as $item) {
            if (!is_numeric($item['id'] ?? null)) {
                continue;
            }

            $this->database->table(ModuleSimbiozaUser::TABLE_PENDING_DELIVERIES)
                ->where('id', '=', (int)$item['id'])
                ->update(['delivered_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * HR: Gradi ograničeni ključ za objedinjavanje istovrsnih promjena unutar pet minuta.
     * EN: Builds a bounded key for coalescing equivalent changes within five minutes.
     */
    private function dedupKey(FollowActivity $activity, int $userId): string
    {
        $bucket = (int)floor(time() / 300);

        if ($activity->dedupIdentity !== null && trim($activity->dedupIdentity) !== '') {
            return substr(
                'follow:' . trim($activity->dedupIdentity) . ':' . $userId . ':' . $bucket,
                0,
                190,
            );
        }

        return substr(
            'follow:' . $activity->eventKey . ':' . $activity->targetType . ':'
            . $activity->targetId . ':' . $userId . ':' . $bucket,
            0,
            190,
        );
    }

    /**
     * HR: Kodira neosjetljivi kontekst sažetka u stabilan JSON.
     * EN: Encodes non-sensitive digest context as stable JSON.
     *
     * @param array<string,mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * HR: Dekodira kontekst sažetka, a oštećeni payload svodi na prazan objekt.
     * EN: Decodes digest context, reducing malformed payloads to an empty object.
     *
     * @return array<string,mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (!is_scalar($payload) || trim((string)$payload) === '') {
            return [];
        }

        try {
            $decoded = json_decode((string)$payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $this->stringKeys($decoded) : [];
    }

    /**
     * HR: Filtrira dekodirani ili ORM redak na tekstualne ključeve.
     * EN: Filters a decoded or ORM row to string keys.
     *
     * @param array<array-key,mixed> $row
     * @return array<string,mixed>
     */
    private function stringKeys(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** HR: Sigurno normalizira skalarni red ili payload. EN: Safely normalizes a scalar row or payload value. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** HR: Generira prenosivi UUID v4. EN: Generates a portable UUID v4. */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
