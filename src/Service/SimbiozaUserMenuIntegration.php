<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use HeartPhrame\Config\ConfigInterface;
use Psr\Container\ContainerInterface;
use Throwable;

use function array_key_exists;
use function array_replace;
use function is_array;
use function is_file;
use function is_object;
use function is_string;
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
            $path = is_object($repository) && method_exists($repository, 'jsonPathForSection')
                ? $repository->jsonPathForSection('settings')
                : null;
            if (!is_string($path) || $path === '') {
                return;
            }

            $items = $this->read($path);
            $definition = $this->definition();
            $existing = null;
            $this->removeById($items, self::ITEM_ID, $existing);
            if (is_array($existing)) {
                $order = $existing['order'] ?? null;
                $definition = array_replace($existing, $definition);
                if ($order !== null) {
                    $definition['order'] = $order;
                }
            }

            if (!$this->appendToParent($items, 'workspace.settings.group', $definition)) {
                return;
            }

            $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                file_put_contents($path, $json . PHP_EOL, LOCK_EX);
            }
        } catch (Throwable) {
            // HR: Opcionalni izbornik ne smije zaustaviti osobna područja.
            // EN: The optional menu must not stop personal-space functionality.
        }
    }

    /**
     * HR: Čita postojeće stavke menija iz JSON datoteke.
     * EN: Reads existing menu items from the JSON file.
     *
     * @return list<array<string,mixed>>
     */
    private function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($path), true);

        return $this->items($decoded);
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

    /**
     * HR: Rekurzivno uklanja staru stavku uz čuvanje korisničkog redoslijeda.
     * EN: Recursively removes the old item while preserving user ordering.
     *
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed>|null $removed
     */
    private function removeById(array &$items, string $id, ?array &$removed): void
    {
        $kept = [];
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $id) {
                $removed ??= $item;
                continue;
            }

            $children = $this->items($item['children'] ?? null);
            $this->removeById($children, $id, $removed);
            if ($children !== []) {
                $item['children'] = $children;
            } elseif (array_key_exists('children', $item)) {
                $item['children'] = [];
            }

            $kept[] = $item;
        }

        $items = $kept;
    }

    /**
     * HR: Rekurzivno dodaje stavku u postojeću roditeljsku grupu.
     * EN: Recursively appends the item to an existing parent group.
     *
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $child
     */
    private function appendToParent(array &$items, string $parentId, array $child): bool
    {
        foreach ($items as &$item) {
            if (($item['id'] ?? null) === $parentId) {
                $children = $this->items($item['children'] ?? null);
                $children[] = $child;
                $item['children'] = $children;
                unset($item);

                return true;
            }

            $children = $this->items($item['children'] ?? null);
            if ($children !== [] && $this->appendToParent($children, $parentId, $child)) {
                $item['children'] = $children;
                unset($item);

                return true;
            }
        }

        unset($item);

        return false;
    }

    /**
     * HR: Normalizira nepouzdani JSON niz u listu objekata s tekstualnim ključevima.
     * EN: Normalizes an untrusted JSON array into a list of objects with string keys.
     *
     * @return list<array<string,mixed>>
     */
    private function items(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $item = [];
            foreach ($candidate as $key => $field) {
                if (is_string($key)) {
                    $item[$key] = $field;
                }
            }

            $items[] = $item;
        }

        return $items;
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
