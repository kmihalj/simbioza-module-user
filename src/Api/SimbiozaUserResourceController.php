<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Api;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationPreferenceService;
use AaiEduHr\SimbiozaModuleUser\Event\UserFollowChanged;
use AaiEduHr\SimbiozaModuleUser\Service\CalendarSubscriptionSynchronizer;
use AaiEduHr\SimbiozaModuleUser\Service\FollowService;
use AaiEduHr\SimbiozaModuleUser\Service\FollowTargetService;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use AaiEduHr\SimbiozaModuleUser\Service\UserPreferenceService;
use JsonException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

use function is_array;
use function is_bool;
use function is_scalar;
use function json_decode;
use function sprintf;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * HR: API adapter ograničen na praćenja i postavke vlasnika API ključa.
 * EN: API adapter restricted to follows and preferences of the API-key owner.
 */
final readonly class SimbiozaUserResourceController
{
    /** HR: Prima zajednički API transport i domenske servise. EN: Receives shared API transport and domain services. */
    public function __construct(
        private ApiResponseFactory $responses,
        private FollowService $follows,
        private UserPreferenceService $preferences,
        private NotificationPreferenceService $notificationPreferences,
        private PersonalWorkspaceService $personalWorkspaces,
        private ?CalendarSubscriptionSynchronizer $calendarSubscriptions = null,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /** HR: Vraća ACL-filtrirana osobna praćenja. EN: Returns ACL-filtered personal follows. */
    public function listFollows(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute($request, 'follows:read', function (int $userId) use ($request): array {
            $this->calendarSubscriptions?->syncUser($userId);
            $query = $request->getQueryParams();

            return $this->follows->listForUser(
                $userId,
                $this->scalar($query['type'] ?? null),
                $this->scalar($query['search'] ?? null),
            );
        });
    }

    /** HR: Dodaje vlastito praćenje nakon domenske ACL provjere. EN: Adds an owned follow after the domain ACL check. */
    public function createFollow(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute($request, 'follows:write', function (int $userId) use ($request): array {
            $payload = $this->jsonBody($request);
            $type = $this->scalar($payload['target_type'] ?? null);
            $id = $this->scalar($payload['target_id'] ?? null);
            $follow = $this->follows->follow(
                $userId,
                $type,
                $id,
                [
                    'document_id' => $this->scalar($payload['document_id'] ?? null),
                    'label_snapshot' => $this->scalar($payload['label'] ?? null),
                ],
                $this->scalar($payload['email_mode_override'] ?? null) ?: null,
            );
            $this->dispatch(new UserFollowChanged($userId, 'followed', $type, $id));

            return $follow;
        }, 201);
    }

    /** HR: Uklanja samo praćenje vlasnika ključa. EN: Removes only a follow owned by the key owner. */
    public function deleteFollow(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (!$identity->permits('follows:write')) {
            return $this->scopeProblem($request, 'follows:write');
        }

        $type = $this->route($request, 'type');
        $id = $this->route($request, 'targetId');
        if ($type === FollowTargetService::TYPE_CALENDAR) {
            $this->calendarSubscriptions?->syncUser($identity->userId());
        }

        $removed = $this->follows->isFollowing($identity->userId(), $type, $id)
            && ($type === FollowTargetService::TYPE_CALENDAR
                ? $this->follows->excludeAutomaticFollow(
                    $identity->userId(),
                    $type,
                    $id,
                    'calendar_subscription',
                )
                : $this->follows->unfollow($identity->userId(), $type, $id));
        if (!$removed) {
            return $this->responses->problem(
                $request,
                404,
                'follow_not_found',
                __('Praćenje nije pronađeno'),
                __('Traženo osobno praćenje ne postoji.'),
            );
        }

        $this->dispatch(new UserFollowChanged($identity->userId(), 'unfollowed', $type, $id));

        return $this->responses->noContent($request);
    }

    /** HR: Vraća objedinjene osobne postavke kanala i ritma. EN: Returns combined personal channel and cadence preferences. */
    public function getPreferences(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute($request, 'follows:read', fn(int $userId): array => [
            ...$this->preferences->forUser($userId),
            'email_enabled' => $this->notificationPreferences->emailEnabled($userId),
        ]);
    }

    /** HR: Djelomično ažurira osobne postavke bez promjene tuđih zapisa. EN: Partially updates personal preferences without changing another user's rows. */
    public function updatePreferences(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute($request, 'follows:write', function (int $userId) use ($request): array {
            $payload = $this->jsonBody($request);
            $current = $this->preferences->forUser($userId);
            $emailEnabled = $this->notificationPreferences->emailEnabled($userId);
            if (isset($payload['email_enabled'])) {
                if (!is_bool($payload['email_enabled'])) {
                    throw new RuntimeException(__('Polje "email_enabled" mora biti true ili false.'));
                }

                $emailEnabled = $payload['email_enabled'];
            }

            $mode = isset($payload['email_mode'])
                ? $this->scalar($payload['email_mode'])
                : (string)$current['email_mode'];
            $notifyOwn = $current['notify_own_changes'];
            if (isset($payload['notify_own_changes'])) {
                if (!is_bool($payload['notify_own_changes'])) {
                    throw new RuntimeException(__('Polje "notify_own_changes" mora biti true ili false.'));
                }

                $notifyOwn = $payload['notify_own_changes'];
            }

            if (!$emailEnabled) {
                $mode = UserPreferenceService::EMAIL_OFF;
            }

            $this->notificationPreferences->saveEmailEnabled($userId, $emailEnabled);
            $saved = $this->preferences->save($userId, $mode, $notifyOwn);
            $this->dispatch(new UserFollowChanged($userId, 'preferences_updated'));

            return [...$saved, 'email_enabled' => $emailEnabled];
        });
    }

    /** HR: Vraća osobno područje vlasnika API ključa bez tuđih podataka. EN: Returns the API-key owner's personal Workspace without other users' data. */
    public function getPersonalWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute($request, 'workspaces:read', function (int $userId): array {
            $mapping = $this->personalWorkspaces->forUser($userId);
            $workspace = is_array($mapping['workspace'] ?? null) ? $mapping['workspace'] : null;

            return [
                'exists' => is_array($workspace),
                'deleted' => (bool)($mapping['is_deleted'] ?? false),
                'automatic_creation_enabled' => $this->personalWorkspaces->automaticCreationEnabled()
                    && $this->personalWorkspaces->automaticCreationEnabledForUser($userId),
                'workspace' => is_array($workspace) ? [
                    'id' => is_numeric($workspace['id'] ?? null) ? (int)$workspace['id'] : null,
                    'slug' => $this->scalar($workspace['slug'] ?? null),
                    'name' => $this->scalar($workspace['name'] ?? null),
                    'visibility' => $this->scalar($workspace['visibility'] ?? null),
                ] : null,
            ];
        });
    }

    /**
     * HR: Provjerava scope, izvršava osobnu radnju i prevodi domenske pogreške
     *     u ujednačene API problem odgovore.
     * EN: Checks the scope, executes the personal operation, and translates
     *     domain failures into consistent API problem responses.
     *
     * @param callable(int):mixed $operation
     */
    private function execute(
        ServerRequestInterface $request,
        string $scope,
        callable $operation,
        int $status = 200,
    ): ResponseInterface {
        $identity = $this->identity($request);
        if (!$identity->permits($scope)) {
            return $this->scopeProblem($request, $scope);
        }

        try {
            return $this->responses->success(
                $request,
                $operation($identity->userId()),
                $status,
                links: ['self' => $this->responses->requestTarget($request)],
            );
        } catch (JsonException $exception) {
            return $this->responses->problem(
                $request,
                400,
                'invalid_json',
                __('Neispravan JSON'),
                $exception->getMessage(),
            );
        } catch (RuntimeException $exception) {
            return $this->responses->problem(
                $request,
                422,
                'follow_validation_failed',
                __('Zahtjev nije valjan'),
                $exception->getMessage(),
            );
        } catch (Throwable) {
            return $this->responses->problem(
                $request,
                500,
                'follow_operation_failed',
                __('Operacija nije uspjela'),
                __('Operaciju nad osobnim praćenjima trenutačno nije moguće izvršiti.'),
            );
        }
    }

    /** HR: Vraća API identitet vlasnika ključa. EN: Returns the API-key owner's identity. */
    private function identity(ServerRequestInterface $request): AuthApiIdentity
    {
        $identity = $request->getAttribute(ModuleApi::IDENTITY_ATTRIBUTE);
        if (!$identity instanceof AuthApiIdentity) {
            throw new RuntimeException('Authenticated API identity is missing.');
        }

        return $identity;
    }

    /** HR: Vraća standardnu zabranu nedostajućeg scopea. EN: Returns the standard missing-scope denial. */
    private function scopeProblem(ServerRequestInterface $request, string $scope): ResponseInterface
    {
        return $this->responses->problem(
            $request,
            403,
            'insufficient_scope',
            __('Pristup nije dozvoljen'),
            sprintf(__('API ključ nema potreban scope "%s".'), $scope),
        );
    }

    /**
     * HR: Dekodira JSON objekt zahtjeva i odbija liste ili neispravne ključeve.
     * EN: Decodes the request JSON object and rejects lists or invalid keys.
     *
     * @return array<string,mixed>
     */
    private function jsonBody(ServerRequestInterface $request): array
    {
        $body = trim((string)$request->getBody());
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException(__('JSON tijelo mora biti objekt.'));
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException(__('JSON tijelo mora biti objekt.'));
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /** HR: Čita obavezni skalarni parametar rute. EN: Reads a required scalar route parameter. */
    private function route(ServerRequestInterface $request, string $name): string
    {
        $value = $this->scalar($request->getAttribute($name));
        if ($value === '') {
            throw new RuntimeException(__('Identifikator praćenja nije valjan.'));
        }

        return $value;
    }

    /** HR: Normalizira skalarni tekst. EN: Normalizes scalar text. */
    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** HR: Audit događaj ne smije prekinuti API radnju. EN: An audit event must not interrupt the API operation. */
    private function dispatch(UserFollowChanged $event): void
    {
        try {
            $this->events?->dispatch($event);
        } catch (Throwable) {
            // HR: Audit je sekundaran kanal. EN: Audit is a secondary channel.
        }
    }
}
