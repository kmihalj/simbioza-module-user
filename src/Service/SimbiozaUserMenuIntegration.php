<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use HeartPhrame\Config\ConfigInterface;
use Psr\Container\ContainerInterface;
use Throwable;

use function is_object;
use function method_exists;

/** HR: Opcionalno dodaje osobna područja u zajedničke postavke. EN: Optionally adds personal Workspaces to shared Settings. */
final readonly class SimbiozaUserMenuIntegration
{
    private const ITEM_ID = 'simbioza-user.personal-workspaces.settings';

    private const MENU_PACKAGE = 'aaieduhr/heartphrame-module-menu';

    private const MENU_REPOSITORY = 'AaiEduHr\\HeartPhrameModuleMenu\\Service\\MenuConfigRepository';

    /** HR: Prima container i konfiguraciju bez ovisnosti o Menu klasama. EN: Receives the container and config without depending on Menu classes. */
    public function __construct(
        private ContainerInterface $container,
        private ConfigInterface $config,
    ) {
    }

    /** HR: Dodaje stavku u postojeću grupu Područja i čuva korisnički redoslijed. EN: Adds the item to the existing Workspaces group and preserves user ordering. */
    public function register(): void
    {
        if (!$this->menuEnabled() || !class_exists(self::MENU_REPOSITORY)) {
            return;
        }

        try {
            $repository = $this->container->get(self::MENU_REPOSITORY);
            if (!is_object($repository) || !method_exists($repository, 'upsertItemsForSection')) {
                return;
            }

            $repository->upsertItemsForSection('settings', [$this->definition()]);
        } catch (Throwable) {
            // HR: Opcionalni izbornik ne smije zaustaviti osobna područja.
            // EN: The optional menu must not stop personal-space functionality.
        }
    }

    /**
     * HR: Vraća prenosivu definiciju stavke osobnih područja.
     * EN: Returns the portable personal-spaces menu item definition.
     *
     * @return array<string,mixed>
     */
    private function definition(): array
    {
        return [
            'id' => self::ITEM_ID,
            'parent_id' => 'workspace.settings.group',
            'label' => ['hr' => 'Osobna područja', 'en' => 'Personal Workspaces'],
            'route' => 'simbioza-user.personal-workspaces.settings',
            'url' => '',
            'query' => '',
            'order' => 60,
            'enabled' => true,
            'level' => 1,
        ];
    }

    /** HR: Provjerava je li Menu stvarno uključen. EN: Checks whether Menu is actually enabled. */
    private function menuEnabled(): bool
    {
        return in_array(
            self::MENU_PACKAGE,
            $this->config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [],
            true,
        );
    }
}
