<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Account;

use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountNavigationProviderInterface;
use AaiEduHr\SimbiozaModuleUser\Security\SimbiozaAdminElevationService;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\Routing\UrlGenerator;

/**
 * EN: Adds the Simbioza administrator switch only for eligible users.
 *
 * HR: Dodaje Simbioza administratorski prekidač samo podobnim korisnicima.
 */
final readonly class AdminElevationAccountNavigationProvider implements AuthAccountNavigationProviderInterface
{
    /**
     * EN: Receives the temporary-rights service, route generator, and label translator.
     *
     * HR: Prima servis privremenih ovlasti, generator ruta i prevoditelj oznake.
     */
    public function __construct(
        private SimbiozaAdminElevationService $elevation,
        private UrlGenerator $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * EN: Returns the switch only for an administrator who has a local password.
     *
     * HR: Vraća prekidač samo administratoru koji ima lokalnu lozinku.
     *
     * @return list<array{
     *     key:string,label:string,path:string,order:int,type:'switch',active:bool,method:'get'|'post'
     * }>
     */
    public function itemsForUser(int $userId): array
    {
        if (!$this->elevation->canElevate($userId)) {
            return [];
        }

        $active = $this->elevation->isElevated($userId);

        return [[
            'key' => 'simbioza-user.admin-elevation',
            'label' => $this->translator->trans('Administrator'),
            'path' => $active
                ? $this->urls->getPathFor('simbioza-user.admin-elevation.disable')
                : $this->urls->getPathFor('simbioza-user.admin-elevation'),
            'order' => 10,
            'type' => 'switch',
            'active' => $active,
            'method' => $active ? 'post' : 'get',
        ]];
    }
}
