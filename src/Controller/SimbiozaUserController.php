<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Controller;

use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationPreferenceService;
use AaiEduHr\SimbiozaModuleUser\Event\UserFollowChanged;
use AaiEduHr\SimbiozaModuleUser\Service\FollowService;
use AaiEduHr\SimbiozaModuleUser\Service\FollowTargetService;
use AaiEduHr\SimbiozaModuleUser\Service\UserPreferenceService;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use HeartPhrame\View\CsrfHandler;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function parse_url;
use function preg_split;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * HR: Sigurni HTTP adapter za osobne postavke i praćenja prijavljenog korisnika.
 * EN: Secure HTTP adapter for the authenticated user's personal preferences and follows.
 */
final readonly class SimbiozaUserController
{
    /** HR: Prima poslovne servise, auth kontekst i standardne framework odgovore. EN: Receives business services, auth context, and standard framework responses. */
    public function __construct(
        private ResponseFactory $responses,
        private AuthnHandlerInterface $authn,
        private FollowService $follows,
        private UserPreferenceService $preferences,
        private NotificationPreferenceService $notificationPreferences,
        private UrlGenerator $urls,
        private AlertHandler $alerts,
        private CsrfHandler $csrf,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /** HR: Sprema zadani kanal, ritam i pravilo vlastitih izmjena. EN: Saves the default channel, cadence, and own-change rule. */
    public function savePreferences(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $userId = $this->currentUserId();
        $emailEnabled = $this->checked($body['email_enabled'] ?? null);
        $emailMode = is_scalar($body['email_mode'] ?? null)
            ? (string)$body['email_mode']
            : UserPreferenceService::EMAIL_OFF;
        if (!$emailEnabled) {
            $emailMode = UserPreferenceService::EMAIL_OFF;
        }

        $this->notificationPreferences->saveEmailEnabled($userId, $emailEnabled);
        $this->preferences->save(
            $userId,
            $emailMode,
            $this->checked($body['notify_own_changes'] ?? null),
        );
        $this->dispatch(new UserFollowChanged($userId, 'preferences_updated'));
        $message = __('Postavke praćenja i obavijesti su spremljene.');
        if ($this->expectsJson($request)) {
            return $this->responses->json([
                'ok' => true,
                'message' => $message,
                'email_enabled' => $emailEnabled,
                'email_mode' => $emailMode,
                'notify_own_changes' => $this->checked($body['notify_own_changes'] ?? null),
                'csrf_token' => $this->csrf->getOrGenerateCsrfToken(),
            ]);
        }

        $this->alerts->add(new Alert($message, AlertLevelEnum::Success));

        return $this->responses->redirect($this->profilePath() . '#simbioza-user-preferences');
    }

    /** HR: Uključuje ili isključuje jedno praćenje bez pristupa tuđim zapisima. EN: Enables or disables one follow without accessing another user's rows. */
    public function toggle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $userId = $this->currentUserId();
        $type = $this->text($body['target_type'] ?? null);
        $id = $this->text($body['target_id'] ?? null);
        $returnUrl = $this->safeReturnUrl($this->text($body['return_url'] ?? null));
        if ($this->follows->isFollowing($userId, $type, $id)) {
            if ($type === FollowTargetService::TYPE_CALENDAR) {
                $this->follows->excludeAutomaticFollow($userId, $type, $id, 'calendar_subscription');
            } else {
                $this->follows->unfollow($userId, $type, $id);
            }

            $this->dispatch(new UserFollowChanged($userId, 'unfollowed', $type, $id));
            $message = __('Sadržaj više ne pratite.');
        } else {
            $this->follows->follow(
                $userId,
                $type,
                $id,
                [
                    'document_id' => $this->text($body['document_id'] ?? null),
                    'label_snapshot' => $this->text($body['label'] ?? null),
                ],
                $this->text($body['email_mode_override'] ?? null) ?: null,
            );
            $this->dispatch(new UserFollowChanged($userId, 'followed', $type, $id));
            $message = __(
                'Sadržaj sada pratite. Obavijest nastaje nakon objavljene promjene drugog korisnika; '
                . 'vlastite promjene ovise o postavci profila.',
            );
        }

        $this->alerts->add(new Alert($message, AlertLevelEnum::Success));

        return $this->responses->redirect($returnUrl !== '' ? $returnUrl : $this->profilePath());
    }

    /** HR: Sprema način dostave jedne praćene stavke. EN: Saves one followed item's delivery mode. */
    public function setMode(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $userId = $this->currentUserId();
        $type = $this->text($body['target_type'] ?? null);
        $id = $this->text($body['target_id'] ?? null);
        $mode = $this->text($body['email_mode_override'] ?? null);
        $changed = $this->follows->setEmailMode($userId, $type, $id, $mode);
        if ($changed) {
            $this->dispatch(new UserFollowChanged($userId, 'delivery_updated', $type, $id));
        }

        $message = $changed
            ? __('Način dostave za praćenu stavku je spremljen.')
            : __('Praćena stavka nije pronađena.');
        if ($this->expectsJson($request)) {
            return $this->responses->json([
                'ok' => $changed,
                'message' => $message,
                'mode' => $this->preferences->emailMode($mode),
                'csrf_token' => $this->csrf->getOrGenerateCsrfToken(),
            ], $changed ? 200 : 404);
        }

        $this->alerts->add(new Alert(
            $message,
            $changed ? AlertLevelEnum::Success : AlertLevelEnum::Info,
        ));

        return $this->responses->redirect($this->profilePath() . '#simbioza-user-items');
    }

    /** HR: Primjenjuje prestanak praćenja ili način e-pošte na odabrane stavke. EN: Applies unfollow or an e-mail mode to selected items. */
    public function bulk(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $selected = is_array($body['selected'] ?? null) ? $body['selected'] : [];
        $action = $this->text($body['bulk_action'] ?? null);
        $userId = $this->currentUserId();
        $changed = 0;
        foreach ($selected as $encoded) {
            if (!is_scalar($encoded)) {
                continue;
            }

            $parts = preg_split('/\|/', (string)$encoded, 2);
            $type = is_array($parts) ? trim((string)($parts[0] ?? '')) : '';
            $id = is_array($parts) ? trim((string)($parts[1] ?? '')) : '';
            if ($type === '' || $id === '') {
                continue;
            }

            if (
                $action === 'unfollow'
                && ($type === FollowTargetService::TYPE_CALENDAR
                    ? $this->follows->excludeAutomaticFollow($userId, $type, $id, 'calendar_subscription')
                    : $this->follows->unfollow($userId, $type, $id))
            ) {
                ++$changed;
                $this->dispatch(new UserFollowChanged($userId, 'unfollowed', $type, $id));
            } elseif (str_starts_with($action, 'email:')) {
                $existing = $this->follows->listForUser($userId, $type);
                foreach ($existing as $item) {
                    if ($this->text($item['target_id'] ?? null) !== $id) {
                        continue;
                    }

                    if ($this->follows->setEmailMode($userId, $type, $id, substr($action, 6))) {
                        ++$changed;
                    }

                    break;
                }
            }
        }

        $this->alerts->add(new Alert(
            $changed > 0
                ? sprintf(__('Ažurirane stavke praćenja: %d'), $changed)
                : __('Nije odabrana nijedna stavka za izmjenu.'),
            $changed > 0 ? AlertLevelEnum::Success : AlertLevelEnum::Info,
        ));

        return $this->responses->redirect($this->profilePath() . '#simbioza-user-items');
    }

    /** HR: Vraća stanje gumba praćenja trenutačnog korisnika. EN: Returns follow-button state for the current user. */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $type = $this->text($query['target_type'] ?? null);
        $id = $this->text($query['target_id'] ?? null);

        return $this->responses->json([
            'ok' => true,
            'following' => $this->follows->isFollowing($this->currentUserId(), $type, $id),
        ]);
    }

    /** HR: Poslužuje mali tematski CSS profilne cjeline. EN: Serves the small themed CSS for the profile section. */
    public function styles(): ResponseInterface
    {
        $path = dirname(__DIR__, 2) . '/resources/assets/simbioza-user.css';
        $css = is_file($path) ? file_get_contents($path) : '';

        return $this->responses->html(is_string($css) ? $css : '')
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=0, must-revalidate');
    }

    /**
     * HR: Vraća POST objekt samo sa sigurnim tekstualnim nazivima polja.
     * EN: Returns the POST object with safe string field names only.
     *
     * @return array<string,mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return [];
        }

        $result = [];
        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** HR: Vraća ID prijavljenog korisnika. EN: Returns the authenticated user's ID. */
    private function currentUserId(): int
    {
        $user = $this->authn->userData();
        $id = is_array($user) ? $user['id'] ?? null : null;

        return is_numeric($id) ? (int)$id : 0;
    }

    /** HR: Pretvara checkbox vrijednost u boolean. EN: Converts a checkbox value to a boolean. */
    private function checked(mixed $value): bool
    {
        return is_scalar($value)
            && in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** HR: Vraća skalarni tekst bez whitespacea. EN: Returns trimmed scalar text. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** HR: Dopušta samo lokalnu apsolutnu povratnu putanju. EN: Allows only a local absolute return path. */
    private function safeReturnUrl(string $url): string
    {
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return '';
        }

        $parts = parse_url($url);

        return is_array($parts) && !isset($parts['scheme'], $parts['host']) ? $url : '';
    }

    /** HR: Prepoznaje pozadinski zahtjev profilnog sučelja. EN: Detects a profile UI background request. */
    private function expectsJson(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json')
            || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    /** HR: Vraća putanju osobnog profila. EN: Returns the personal-profile path. */
    private function profilePath(): string
    {
        return $this->urls->namedRouteExists('auth.account.profile')
            ? $this->urls->getPathFor('auth.account.profile')
            : rtrim($this->urls->getBasePath(), '/') . '/auth/account/profile';
    }

    /** HR: Objavljuje opcionalni audit događaj bez utjecaja na poslovni ishod. EN: Dispatches an optional audit event without affecting the business outcome. */
    private function dispatch(UserFollowChanged $event): void
    {
        try {
            $this->events?->dispatch($event);
        } catch (\Throwable) {
            // HR: Audit je sekundaran kanal. EN: Audit is a secondary channel.
        }
    }
}
