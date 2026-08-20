<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- HR/EN: Prevedeni HTML atributi ostaju čitljivi. / Translated HTML attributes remain readable.

/**
 * HR: Administratorske postavke osobnih područja.
 * EN: Administrator settings for personal spaces.
 *
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var bool $tablesReady
 * @var bool $automaticCreationEnabled
 * @var list<array<string,mixed>> $users
 * @var string $savePath
 * @var string $provisionPath
 * @var string $userPolicyPath
 * @var string $createPath
 * @var string $settingsMenuActiveSection
 * @var object|null $menuRenderer
 */

$settingsMenuHtml = null;
if (isset($menuRenderer) && is_object($menuRenderer) && is_callable([$menuRenderer, 'renderSettingsMenu'])) {
    $candidate = $menuRenderer->renderSettingsMenu($settingsMenuActiveSection);
    $settingsMenuHtml = is_string($candidate) ? $candidate : null;
}
$text = static fn(mixed $value): string => is_scalar($value) ? (string)$value : '';
?>
<div class="row g-4">
    <aside class="col-lg-3"><?= is_string($settingsMenuHtml) ? $settingsMenuHtml : '' ?></aside>
    <main class="col-lg-9">
        <section class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <header class="mb-4">
                    <h1 class="h3 mb-1"><?= $this->escape($title) ?></h1>
                    <p class="text-body-secondary mb-0">
                        <?= $this->escape(__('Osobno područje je obično ograničeno područje: vlasnik ima sva prava, a drugi ga vide samo kada im se izričito dodijeli pristup.')) ?>
                    </p>
                </header>

                <?php if (!$tablesReady) : ?>
                    <div class="alert alert-warning" role="alert">
                        <?= $this->escape(__('Migracija osobnih područja nije primijenjena.')) ?>
                    </div>
                <?php else : ?>
                    <form method="post" action="<?= $this->escape($savePath) ?>" class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                        <div class="form-check form-switch">
                            <input type="hidden" name="enabled" value="0">
                            <input
                                id="personal-workspaces-auto-create"
                                class="form-check-input"
                                type="checkbox"
                                name="enabled"
                                value="1"
                                <?= $automaticCreationEnabled ? 'checked' : '' ?>
                            >
                            <label class="form-check-label fw-semibold" for="personal-workspaces-auto-create">
                                <?= $this->escape(__('Automatski izradi osobno područje pri prvoj prijavi')) ?>
                            </label>
                            <div class="form-text">
                                <?= $this->escape(__('Promjena vrijedi za buduće prijave; postojeće korisnike možete obraditi zasebnom radnjom.')) ?>
                            </div>
                        </div>
                        <button class="btn btn-primary" type="submit"><?= $this->escape(__('Spremi')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($tablesReady) : ?>
            <section class="card shadow-sm">
                <div class="card-body p-4">
                    <header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="h5 mb-1"><?= $this->escape(__('Postojeći korisnici')) ?></h2>
                            <p class="text-body-secondary mb-0">
                                <?= $this->escape(__('Mapiranje je odvojeno od vlasništva pa isti korisnik smije posjedovati i druga obična područja.')) ?>
                            </p>
                        </div>
                        <form method="post" action="<?= $this->escape($provisionPath) ?>">
                            <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                            <button class="btn btn-secondary" type="submit">
                                <?= $this->escape(__('Izradi osobna područja postojećim korisnicima')) ?>
                            </button>
                        </form>
                    </header>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?= $this->escape(__('Korisnik')) ?></th>
                                    <th><?= $this->escape(__('Osobno područje')) ?></th>
                                    <th><?= $this->escape(__('Automatska izrada')) ?></th>
                                    <th class="text-end"><?= $this->escape(__('Radnje')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($users as $user) : ?>
                                <?php
                                $userId = is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
                                $workspace = is_array($user['personal_workspace'] ?? null) ? $user['personal_workspace'] : null;
                                $deleted = is_array($workspace) && (bool)($workspace['is_deleted'] ?? false);
                                $workspacePath = is_string($user['personal_workspace_path'] ?? null)
                                    ? $user['personal_workspace_path']
                                    : '';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= $this->escape($text($user['display_name'] ?? $user['login_identifier'] ?? '')) ?></strong>
                                        <small class="d-block text-body-secondary"><?= $this->escape($text($user['login_identifier'] ?? '')) ?></small>
                                    </td>
                                    <td>
                                        <?php if (is_array($workspace) && !$deleted) : ?>
                                            <a href="<?= $this->escape($workspacePath) ?>">
                                                <?= $this->escape($text($workspace['name'] ?? '')) ?>
                                            </a>
                                        <?php elseif ($deleted) : ?>
                                            <span class="badge text-bg-warning"><?= $this->escape(__('Obrisano — moguće ga je vratiti u postavkama područja')) ?></span>
                                        <?php else : ?>
                                            <span class="text-body-secondary"><?= $this->escape(__('Nije izrađeno')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="<?= $this->escape($userPolicyPath) ?>" class="d-flex align-items-center gap-2">
                                            <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                            <input type="hidden" name="enabled" value="0">
                                            <div class="form-check form-switch mb-0">
                                                <input
                                                    id="personal-workspace-policy-<?= $userId ?>"
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    name="enabled"
                                                    value="1"
                                                    <?= (bool)($user['auto_create_enabled'] ?? true) ? 'checked' : '' ?>
                                                >
                                                <label class="visually-hidden" for="personal-workspace-policy-<?= $userId ?>">
                                                    <?= $this->escape(__('Dopusti automatsku izradu')) ?>
                                                </label>
                                            </div>
                                            <button class="btn btn-sm btn-secondary" type="submit"><?= $this->escape(__('Spremi')) ?></button>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!is_array($workspace)) : ?>
                                            <form method="post" action="<?= $this->escape($createPath) ?>" class="d-inline">
                                                <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                                                <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                <button class="btn btn-sm btn-primary" type="submit"><?= $this->escape(__('Izradi sada')) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($users === []) : ?>
                                <tr><td colspan="4" class="text-body-secondary text-center py-4"><?= $this->escape(__('Nema aktivnih korisnika.')) ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
