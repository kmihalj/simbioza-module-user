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
 * @var bool $selfCreationEnabled
 * @var list<array<string,mixed>> $users
 * @var string $savePath
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
                    <form method="post" action="<?= $this->escape($savePath) ?>" data-personal-workspace-settings>
                        <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3">
                            <div class="d-flex flex-column gap-3">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="auto_create_enabled" value="0">
                                    <input
                                        id="personal-workspaces-auto-create"
                                        class="form-check-input"
                                        type="checkbox"
                                        name="auto_create_enabled"
                                        value="1"
                                        <?= $automaticCreationEnabled ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label fw-semibold" for="personal-workspaces-auto-create">
                                        <?= $this->escape(__('Automatski izradi osobno područje pri prvoj prijavi')) ?>
                                    </label>
                                    <div class="form-text">
                                        <?= $this->escape(__('Promjena vrijedi pri sljedećim prijavama; već izrađena osobna područja ostaju nepromijenjena.')) ?>
                                    </div>
                                </div>
                                <div class="form-check form-switch">
                                    <input type="hidden" name="self_create_enabled" value="0">
                                    <input
                                        id="personal-workspaces-self-create"
                                        class="form-check-input"
                                        type="checkbox"
                                        name="self_create_enabled"
                                        value="1"
                                        <?= $selfCreationEnabled ? 'checked' : '' ?>
                                        <?= $automaticCreationEnabled ? 'disabled' : '' ?>
                                    >
                                    <label class="form-check-label fw-semibold" for="personal-workspaces-self-create">
                                        <?= $this->escape(__('Omogući korisnicima izradu osobnog područja')) ?>
                                    </label>
                                    <div class="form-text">
                                        <?= $this->escape(__('Kada automatska izrada nije uključena, korisnik bez osobnog područja može ga izraditi u svojem profilu.')) ?>
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-primary" type="submit"><?= $this->escape(__('Spremi')) ?></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($tablesReady) : ?>
            <section class="card shadow-sm">
                <div class="card-body p-4">
                    <header class="mb-3">
                        <div>
                            <h2 class="h5 mb-1"><?= $this->escape(__('Izrađena osobna područja')) ?></h2>
                            <p class="text-body-secondary mb-0">
                                <?= $this->escape(__('Pregled korisnika kojima je osobno područje već izrađeno automatski ili iz njihovog profila.')) ?>
                            </p>
                        </div>
                    </header>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?= $this->escape(__('Korisnik')) ?></th>
                                    <th><?= $this->escape(__('Osobno područje')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($users as $user) : ?>
                                <?php
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
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($users === []) : ?>
                                <tr><td colspan="2" class="text-body-secondary text-center py-4"><?= $this->escape(__('Nema izrađenih osobnih područja.')) ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>

<script>
(() => {
    const form = document.querySelector('[data-personal-workspace-settings]');
    const automatic = form?.querySelector('#personal-workspaces-auto-create');
    const selfCreation = form?.querySelector('#personal-workspaces-self-create');
    if (!(automatic instanceof HTMLInputElement) || !(selfCreation instanceof HTMLInputElement)) return;

    const refresh = () => {
        selfCreation.disabled = automatic.checked;
        if (automatic.checked) selfCreation.checked = false;
    };
    automatic.addEventListener('change', refresh);
    refresh();
})();
</script>
