<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use Closure;
use Psr\Container\ContainerInterface;
use Throwable;

use function class_exists;
use function is_callable;
use function is_scalar;

/** HR: Čita opcionalni globalni Theme policy bez obavezne Composer ovisnosti. EN: Reads the optional global Theme policy without a required Composer dependency. */
final readonly class UserThemePolicy
{
    private const REPOSITORY = 'AaiEduHr\\HeartPhrameModuleTheme\\Service\\ThemeConfigRepository';

    /** HR: Prima aplikacijski container za sigurnu opcionalnu integraciju. EN: Receives the application container for safe optional integration. */
    public function __construct(private ContainerInterface $container)
    {
    }

    /** HR: Osobni izbor dopušten je samo uz globalni automatski policy. EN: Personal selection is allowed only with the global automatic policy. */
    public function selectionAvailable(): bool
    {
        if (!class_exists(self::REPOSITORY) || !$this->container->has(self::REPOSITORY)) {
            return false;
        }

        try {
            $repository = $this->container->get(self::REPOSITORY);
            $modePolicy = [$repository, 'modePolicy'];
            if (!is_callable($modePolicy)) {
                return false;
            }

            $mode = Closure::fromCallable($modePolicy)();

            return is_scalar($mode) && (string)$mode === UserPreferenceService::THEME_AUTO;
        } catch (Throwable) {
            return false;
        }
    }
}
