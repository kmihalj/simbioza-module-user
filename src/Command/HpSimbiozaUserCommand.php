<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Command;

use AaiEduHr\SimbiozaModuleUser\Service\FollowDeliveryService;
use HeartPhrame\Config\ConfigInterface;
use RuntimeException;

use function array_slice;
use function array_values;
use function date;
use function is_dir;
use function is_file;
use function is_scalar;
use function mkdir;
use function rtrim;
use function str_starts_with;
use function strtolower;
use function trim;

/** HR: Instalira migraciju i pokreće obradu dnevnih sažetaka. EN: Installs the migration and dispatches daily digests. */
final readonly class HpSimbiozaUserCommand
{
    /** HR: Prima konfiguraciju i servis dostave. EN: Receives configuration and the delivery service. */
    public function __construct(
        private ConfigInterface $config,
        private FollowDeliveryService $delivery,
    ) {
    }

    /**
     * HR: Usmjerava CLI podnaredbu na instalaciju, obradu sažetaka ili pomoć.
     * EN: Routes the CLI subcommand to installation, digest dispatch, or help.
     *
     * @param array<int,string> $arguments
     * @param array<string,mixed> $options
     */
    public function run(array $arguments = [], array $options = []): int
    {
        $subcommand = strtolower(trim((string)($arguments[0] ?? 'help')));

        return match ($subcommand) {
            'install', 'install-migration' => $this->installMigration(
                array_values(array_slice($arguments, 1)),
                $options,
            ),
            'dispatch', 'digest', 'dispatch-digests' => $this->dispatchDigests($arguments, $options),
            'help', '--help', '-h' => $this->help(),
            default => 1,
        };
    }

    /**
     * HR: Kopira prenosivu inicijalnu migraciju u aplikacijski direktorij.
     * EN: Copies the portable initial migration into the application directory.
     *
     * @param array<int,string> $arguments
     * @param array<string,mixed> $options
     */
    public function installMigration(array $arguments = [], array $options = []): int
    {
        $directory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . '/resources/migrations/initial_simbioza_user_schema.php';
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak migracije Simbioza korisnik nije pronađen.'));
        }

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $target = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . date('YmdHis') . '_install_simbioza_user_module_schema.php';
        $content = file_get_contents($template);
        if (!is_string($content) || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati migraciju Simbioza korisnik.'));
        }

        echo __('Kreirana je migracija: ') . $target . PHP_EOL;

        return 0;
    }

    /**
     * HR: Pokreće ograničenu obradu dospjelih dnevnih sažetaka.
     * EN: Dispatches a bounded batch of due daily digests.
     *
     * @param array<int,string> $arguments
     * @param array<string,mixed> $options
     */
    public function dispatchDigests(array $arguments = [], array $options = []): int
    {
        $limit = is_scalar($options['limit'] ?? null) ? (int)$options['limit'] : 500;
        $sent = $this->delivery->dispatchDueDigests($limit);
        echo sprintf(__('Poslani dnevni sažetci: %d'), $sent) . PHP_EOL;

        return 0;
    }

    /** HR: Ispisuje CLI primjere za početnike i automatizaciju. EN: Prints CLI examples for beginners and automation. */
    public function help(): int
    {
        echo 'vendor/bin/hph simbioza-user:install-migration' . PHP_EOL;
        echo 'vendor/bin/hph simbioza-user:dispatch --limit=500' . PHP_EOL;

        return 0;
    }

    /**
     * HR: Razrješava apsolutni ili aplikaciji relativni direktorij migracija.
     * EN: Resolves an absolute or application-relative migration directory.
     *
     * @param array<string,mixed> $options
     */
    private function targetDirectory(array $options): string
    {
        $path = is_scalar($options['path'] ?? null) ? trim((string)$options['path']) : '';
        if ($path === '') {
            return rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR) . '/database/migrations';
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR) . '/' . rtrim($path, DIRECTORY_SEPARATOR);
    }
}
