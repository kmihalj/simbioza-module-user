<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Account;

use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountSectionProviderInterface;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationPreferenceService;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use AaiEduHr\SimbiozaModuleUser\Service\CalendarSubscriptionSynchronizer;
use AaiEduHr\SimbiozaModuleUser\Service\FollowService;
use AaiEduHr\SimbiozaModuleUser\Service\UserPreferenceService;
use HeartPhrame\Routing\UrlGenerator;

use function is_scalar;
use function usort;

/**
 * HR: Dodaje objedinjene postavke praćenja i popis praćenog sadržaja u Auth profil.
 * EN: Adds combined follow settings and the followed-content list to the Auth profile.
 */
final readonly class SimbiozaUserAccountSectionProvider implements AuthAccountSectionProviderInterface
{
    /** HR: Prima samo javne poslovne servise potrebne profilu. EN: Receives only public business services required by the profile. */
    public function __construct(
        private FollowService $follows,
        private UserPreferenceService $preferences,
        private NotificationPreferenceService $notificationPreferences,
        private UrlGenerator $urls,
        private ?CalendarSubscriptionSynchronizer $calendarSubscriptions = null,
    ) {
    }

    /**
     * HR: Vraća dvije povezane profilne cjeline kada je migracija spremna.
     * EN: Returns two related profile sections when the migration is ready.
     *
     * @return array{key:string,package:string,partial:string,data:array<string,mixed>,group:string,order:int}|null
     */
    public function sectionForUser(int $userId): ?array
    {
        if ($userId <= 0 || !$this->follows->tablesReady()) {
            return null;
        }

        // HR: Stare i nove Calendar pretplate prikazuje u istom popisu praćenja.
        // EN: Shows legacy and new Calendar subscriptions in the unified follow list.
        $this->calendarSubscriptions?->syncUser($userId);
        $preferences = $this->preferences->forUser($userId);
        $follows = $this->follows->listForUser($userId);
        $calendarRows = [];
        foreach ($follows as $item) {
            $type = is_scalar($item['target_type'] ?? null) ? (string)$item['target_type'] : '';
            $targetId = is_scalar($item['target_id'] ?? null) ? (string)$item['target_id'] : '';
            if ($type === 'calendar' && $targetId !== '') {
                $calendarRows[$targetId] = true;
            }
        }

        /*
         * HR: Pretplaćeni kalendar ostaje u pregledu i nakon što korisnik
         *     isključi njegove obavijesti, kako bi ih mogao ponovno uključiti.
         * EN: A subscribed calendar remains in the overview after the user
         *     disables its notifications, so they can enable them again.
         */
        foreach ($this->calendarSubscriptions?->subscribedCalendarIdsForUser($userId) ?? [] as $calendarId) {
            if (isset($calendarRows[(string)$calendarId])) {
                continue;
            }

            $candidate = $this->follows->availableTarget($userId, 'calendar', (string)$calendarId);
            if ($candidate !== null) {
                $follows[] = [...$candidate, 'source' => 'calendar_subscription'];
            }
        }

        $defaultMode = is_scalar($preferences['email_mode'] ?? null)
            ? (string)$preferences['email_mode']
            : UserPreferenceService::EMAIL_OFF;
        foreach ($follows as $index => $item) {
            $override = is_scalar($item['email_mode_override'] ?? null)
                ? trim((string)$item['email_mode_override'])
                : '';
            $follows[$index]['delivery_mode'] = (bool)($item['following'] ?? false)
                ? ($override !== '' ? $override : $defaultMode)
                : '';
        }

        usort($follows, static function (array $left, array $right): int {
            $leftFollowed = (bool)($left['following'] ?? false);
            $rightFollowed = (bool)($right['following'] ?? false);
            if ($leftFollowed !== $rightFollowed) {
                return $leftFollowed ? -1 : 1;
            }

            $leftLabel = is_scalar($left['label'] ?? null) ? (string)$left['label'] : '';
            $rightLabel = is_scalar($right['label'] ?? null) ? (string)$right['label'] : '';

            return strcasecmp($leftLabel, $rightLabel);
        });

        return [
            'key' => 'simbioza-following',
            'package' => ModuleSimbiozaUser::PACKAGE_NAME,
            'partial' => 'simbioza-user/account_following',
            'group' => 'personal',
            'order' => 200,
            'data' => [
                'preferences' => $preferences,
                'emailEnabled' => $this->notificationPreferences->emailEnabled($userId),
                'follows' => $follows,
                'savePreferencesPath' => $this->path(
                    'simbioza-user.preferences.save',
                    '/account/following/preferences',
                ),
                'bulkPath' => $this->path('simbioza-user.bulk', '/account/following/bulk'),
                'togglePath' => $this->path('simbioza-user.toggle', '/account/following/toggle'),
                'modePath' => $this->path('simbioza-user.mode', '/account/following/mode'),
                'profileFollowingPath' => $this->path('auth.account.profile', '/auth/account/profile')
                    . '#simbioza-user-items',
                'assetsCssPath' => $this->path('simbioza-user.assets.css', '/simbioza-user/assets.css'),
            ],
        ];
    }

    /** HR: Generira named rutu ili prenosivi fallback. EN: Generates a named route or portable fallback. */
    private function path(string $name, string $fallback): string
    {
        return $this->urls->namedRouteExists($name)
            ? $this->urls->getPathFor($name)
            : rtrim($this->urls->getBasePath(), '/') . $fallback;
    }
}
