<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\View\View;
use Throwable;

use function is_array;
use function is_numeric;

/**
 * HR: Izlaže spremljeni osobni način teme glavnom layoutu bez povezivanja
 *     korisničkog modula s konkretnom implementacijom Theme modula.
 * EN: Exposes the stored personal theme mode to the main layout without
 *     coupling the user module to a concrete Theme module implementation.
 */
final readonly class UserThemeViewIntegration
{
    /** HR: Prima osobne postavke, prijavljenog korisnika, prikaz i globalnu politiku. EN: Receives preferences, authenticated user, view, and global policy. */
    public function __construct(
        private UserPreferenceService $preferences,
        private AuthnHandlerInterface $authn,
        private View $view,
        private UserThemePolicy $policy,
    ) {
    }

    /** HR: Registrira sigurnu zadanu vrijednost i osobni izbor prijavljenog korisnika. EN: Registers a safe default and the authenticated user's personal choice. */
    public function register(): void
    {
        $mode = UserPreferenceService::THEME_AUTO;
        try {
            $user = $this->authn->userData();
            $id = is_array($user) ? $user['id'] ?? null : null;
            if (is_numeric($id) && $this->policy->selectionAvailable()) {
                $mode = $this->preferences->forUser((int)$id)['theme_mode'];
            }
        } catch (Throwable) {
            // HR: CLI i instalacijski bootstrap namjerno nemaju pokrenutu sesiju.
            // EN: CLI and installation bootstrap intentionally have no started session.
        }

        $this->view->addGlobal('userThemeMode', $mode);
    }
}
