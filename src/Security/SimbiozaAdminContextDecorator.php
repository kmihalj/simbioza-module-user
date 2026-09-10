<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Security;

use AaiEduHr\HeartPhrameModuleAuth\Auth\AuthUserContextDecoratorInterface;

/**
 * HR: Povezuje generičku Auth ekstenzijsku točku sa Simbioza admin elevacijom.
 * EN: Connects the generic Auth extension point to Simbioza administrator elevation.
 */
final readonly class SimbiozaAdminContextDecorator implements AuthUserContextDecoratorInterface
{
    /** HR: Prima servis koji računa efektivna Simbioza prava. EN: Receives the service that computes effective Simbioza rights. */
    public function __construct(private SimbiozaAdminElevationService $elevation)
    {
    }

    /**
     * HR: Uklanja ili vraća administratorska prava prema stanju session prekidača.
     * EN: Removes or restores administrator rights according to the session switch.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function decorate(array $user): array
    {
        return $this->elevation->effectiveUser($user) ?? $user;
    }

    /** HR: Nova prijava i odjava uvijek vraćaju prekidač u isključeno stanje. EN: New sign-in and sign-out always reset the switch to off. */
    public function reset(): void
    {
        $this->elevation->clear();
    }
}
