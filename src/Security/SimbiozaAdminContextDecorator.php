<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Security;

use AaiEduHr\HeartPhrameModuleAuth\Auth\AuthUserContextDecoratorInterface;

/**
 * EN: Connects the generic Auth extension point to Simbioza administrator elevation.
 *
 * HR: Povezuje generičku Auth ekstenzijsku točku sa Simbioza admin elevacijom.
 */
final readonly class SimbiozaAdminContextDecorator implements AuthUserContextDecoratorInterface
{
    /**
     * EN: Receives the service that computes effective Simbioza rights.
     *
     * HR: Prima servis koji računa efektivna Simbioza prava.
     */
    public function __construct(private SimbiozaAdminElevationService $elevation)
    {
    }

    /**
     * EN: Removes or restores administrator rights according to the session switch.
     *
     * HR: Uklanja ili vraća administratorska prava prema stanju session prekidača.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function decorate(array $user): array
    {
        return $this->elevation->effectiveUser($user) ?? $user;
    }

    /**
     * EN: New sign-in and sign-out always reset the switch to off.
     *
     * HR: Nova prijava i odjava uvijek vraćaju prekidač u isključeno stanje.
     */
    public function reset(): void
    {
        $this->elevation->clear();
    }
}
