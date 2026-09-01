<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- HR/EN: HTML atributi i prijevodne poruke ostaju čitljivi u predlošku. / HTML attributes and translation messages remain readable in the template.

/**
 * HR: Profilna cjelina za postavke i tablični pregled svih osobnih praćenja.
 * EN: Profile section for settings and a tabular overview of all personal follows.
 *
 * @var \HeartPhrame\View\View $this
 * @var array<string,mixed> $preferences
 * @var bool $emailEnabled
 * @var list<array<string,mixed>> $follows
 * @var string $savePreferencesPath
 * @var string $saveThemeModePath
 * @var bool $themeModeSelectionAvailable
 * @var string $togglePath
 * @var string $modePath
 * @var string $profileFollowingPath
 * @var string $assetsCssPath
 * @var array<string,mixed>|null $personalWorkspace
 * @var string|null $personalWorkspacePath
 */

$preferences = is_array($preferences ?? null) ? $preferences : [];
$follows = is_array($follows ?? null) ? $follows : [];
$emailEnabled = (bool)($emailEnabled ?? false);
$emailMode = is_string($preferences['email_mode'] ?? null) ? $preferences['email_mode'] : 'off';
$themeMode = is_string($preferences['theme_mode'] ?? null) ? $preferences['theme_mode'] : 'auto';
$themeModeLabels = [
    'light' => __('Svijetla'),
    'dark' => __('Tamna'),
    'auto' => __('Automatski'),
    'system' => __('Sistemski'),
];
$themeModeSelectionAvailable = (bool)($themeModeSelectionAvailable ?? false);
$typeLabels = [
    'workspace' => __('Područje'),
    'page' => __('Stranica'),
    'calendar' => __('Kalendar'),
    'task_list' => __('Lista zadataka'),
];
$modeLabels = [
    'off' => __('Samo u aplikaciji'),
    'immediate' => __('E-pošta odmah'),
    'daily' => __('Dnevni sažetak'),
    'important' => __('Samo važne promjene'),
];
$modeDescriptions = [
    'off' => __('Sve promjene odmah su vidljive u aplikaciji, bez slanja e-pošte.'),
    'immediate' => __('Obavijest je odmah u aplikaciji, a kopija e-pošte šalje se bez čekanja.'),
    'daily' => __('Obavijesti su odmah u aplikaciji, a e-pošta ih objedinjuje u jedan sažetak sljedećeg dana.'),
    'important' => __('Sve promjene su u aplikaciji; e-pošta stiže samo za objave, uklanjanja i važne promjene događaja ili zadataka.'),
];
$activeCount = count(array_filter(
    $follows,
    static fn(mixed $item): bool => is_array($item) && (bool)($item['following'] ?? false),
));
$formatDateTime = static function (string $value): string {
    if (trim($value) === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format(__('notification_datetime_format'));
    } catch (Throwable) {
        return $value;
    }
};
$icon = static function (string $name): string {
    return match ($name) {
        'follow' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>',
        'app' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h5"/></svg>',
        'mail' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>',
        'daily' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18M8 14h3M8 17h6"/></svg>',
        'important' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9L12 3Z"/></svg>',
        'open' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5h5v5M19 5l-9 9"/><path d="M19 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    };
};
?>
<?php if (is_string($assetsCssPath ?? null) && $assetsCssPath !== '') : ?>
    <link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">
<?php endif; ?>

<section class="simbioza-user-card card shadow-sm" aria-labelledby="simbioza-following-heading">
    <div class="card-body p-4">
        <h2 class="h5 mb-2" id="simbioza-following-heading"><?= __('Praćenje i obavijesti') ?></h2>
        <p class="text-body-secondary mb-3">
            <?= __('Odredite kako želite primati promjene i upravljajte sadržajem koji pratite.') ?>
        </p>

        <?php if (is_string($personalWorkspacePath ?? null) && $personalWorkspacePath !== '') : ?>
            <?php $personalWorkspaceRow = is_array($personalWorkspace['workspace'] ?? null) ? $personalWorkspace['workspace'] : []; ?>
            <?php $personalWorkspaceName = is_scalar($personalWorkspaceRow['name'] ?? null)
                ? (string)$personalWorkspaceRow['name']
                : __('Otvori osobno područje'); ?>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 border rounded p-3 mb-3">
                <div>
                    <h3 class="h6 mb-1"><?= $this->escape(__('Moje osobno područje')) ?></h3>
                    <p class="text-body-secondary small mb-0">
                        <?= $this->escape(__('Vaše privatno područje vidljivo je samo vama, administratorima i osobama kojima izričito dodijelite pristup.')) ?>
                    </p>
                </div>
                <a class="btn btn-secondary" href="<?= $this->escape($personalWorkspacePath) ?>">
                    <?= $this->escape($personalWorkspaceName) ?>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($themeModeSelectionAvailable) : ?>
        <section class="simbioza-user-preferences mb-3" id="simbioza-user-appearance"
                 aria-labelledby="simbioza-user-appearance-heading">
            <div class="simbioza-user-preferences-header">
                <h3 class="h6 mb-0" id="simbioza-user-appearance-heading">
                    <?= __('Izgled') ?>
                </h3>
            </div>
            <div class="simbioza-user-preferences-body">
                <form method="post" action="<?= $this->escape((string)$saveThemeModePath) ?>">
                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                    <div class="mb-3">
                        <label class="form-label" for="simbioza-user-theme-mode">
                            <?= __('Tema sučelja') ?>
                        </label>
                        <select class="form-select" id="simbioza-user-theme-mode" name="theme_mode">
                            <?php foreach ($themeModeLabels as $mode => $label) : ?>
                                <option value="<?= $this->escape($mode) ?>"
                                    <?= $themeMode === $mode ? 'selected' : '' ?>>
                                    <?= $this->escape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="small text-body-secondary">
                        <?= __('Automatski koristi zadanu postavku teme aplikacije. Sistemski prati svijetli ili tamni način vašeg uređaja.') ?>
                    </p>
                    <button type="submit" class="btn btn-primary">
                        <?= __('Spremi izgled') ?>
                    </button>
                </form>
            </div>
        </section>
        <?php endif; ?>

        <div class="alert alert-info simbioza-user-follow-explanation" role="note">
            <h3 class="h6 alert-heading mb-2"><?= __('Kako rade praćenje i obavijesti?') ?></h3>
            <ul class="small mb-0 ps-3">
                <li><?= __('Stranica obavještava pratitelje nakon objave; samo spremanje nacrta ne šalje obavijest.') ?></li>
                <li><?= __('Pretplata na kalendar uključuje praćenje, ali obavijesti možete isključiti bez prekida pretplate i poslije ih ponovno uključiti.') ?></li>
                <li><?= __('Promjena kalendara ili liste zadataka ugrađene u praćenu stranicu računa se kao promjena te stranice.') ?></li>
                <li><?= __('Vlastite promjene preskaču se dok ne uključite opciju „Obavještavaj me i o mojim vlastitim promjenama”.') ?></li>
            </ul>
        </div>

        <section class="simbioza-user-preferences mb-3" id="simbioza-user-preferences"
                 aria-labelledby="simbioza-user-preferences-heading">
            <div class="simbioza-user-preferences-header">
                <h3 class="h6 mb-0" id="simbioza-user-preferences-heading">
                    <?= __('Postavke obavijesti') ?>
                </h3>
            </div>
            <div class="simbioza-user-preferences-body">
                <form method="post" action="<?= $this->escape((string)$savePreferencesPath) ?>"
                      data-simbioza-preferences-form>
                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="simbioza-user-email-enabled" name="email_enabled" value="1"
                               <?= $emailEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="simbioza-user-email-enabled">
                            <?= __('Šalji mi i e-mail obavijesti') ?>
                        </label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="simbioza-user-email-mode">
                            <?= __('Zadani način dostave') ?>
                        </label>
                        <select class="form-select" id="simbioza-user-email-mode" name="email_mode"
                                <?= $emailEnabled ? '' : 'disabled' ?>>
                            <?php foreach ($modeLabels as $mode => $modeLabel) : ?>
                                <option value="<?= $this->escape($mode) ?>" <?= $emailMode === $mode ? 'selected' : '' ?>>
                                    <?= $this->escape($modeLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="simbioza-user-own-changes" name="notify_own_changes" value="1"
                               <?= !empty($preferences['notify_own_changes']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="simbioza-user-own-changes">
                            <?= __('Obavještavaj me i o mojim vlastitim promjenama') ?>
                        </label>
                    </div>

                    <p class="small text-body-secondary">
                        <?= __('Način dostave uz pojedinu stavku nadjačava ovu zadanu postavku.') ?>
                    </p>
                    <div class="simbioza-user-delivery-help mb-3" role="note">
                        <h4 class="h6 mb-2"><?= __('Što znače načini dostave?') ?></h4>
                        <dl class="small mb-0">
                            <?php foreach ($modeLabels as $mode => $modeLabel) : ?>
                                <div>
                                    <dt><?= $this->escape($modeLabel) ?></dt>
                                    <dd><?= $this->escape($modeDescriptions[$mode]) ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= __('Spremi postavke praćenja') ?>
                    </button>
                </form>
            </div>
        </section>

        <div class="accordion" id="simbioza-user-following-accordion">
            <div class="accordion-item">
                <h3 class="accordion-header" id="simbioza-user-items-heading">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                            data-bs-target="#simbioza-user-items" aria-expanded="false"
                            aria-controls="simbioza-user-items">
                        <?= __('Praćeni sadržaj') ?>
                        <span class="badge text-bg-secondary ms-2"><?= $activeCount ?></span>
                    </button>
                </h3>
                <div id="simbioza-user-items" class="accordion-collapse collapse"
                     aria-labelledby="simbioza-user-items-heading">
                    <div class="accordion-body">
                        <?php if ($follows === []) : ?>
                            <p class="text-body-secondary mb-0">
                                <?= __('Još ne pratite nijednu stranicu, područje, kalendar ili listu zadataka.') ?>
                            </p>
                        <?php else : ?>
                            <div class="simbioza-user-follow-toolbar mb-3">
                                <label class="visually-hidden" for="simbioza-user-follow-search">
                                    <?= __('Pretraži sadržaj') ?>
                                </label>
                                <input type="search" class="form-control" id="simbioza-user-follow-search"
                                       placeholder="<?= $this->escape(__('Pretraži sadržaj')) ?>"
                                       data-simbioza-follow-search>
                                <label class="visually-hidden" for="simbioza-user-follow-filter">
                                    <?= __('Filtriraj prema stanju') ?>
                                </label>
                                <select class="form-select" id="simbioza-user-follow-filter" data-simbioza-follow-filter>
                                    <option value="all"><?= __('Sve stavke') ?></option>
                                    <option value="following"><?= __('Pratim') ?></option>
                                    <option value="not-following"><?= __('Ne pratim') ?></option>
                                    <option value="off"><?= __('Samo u aplikaciji') ?></option>
                                    <option value="immediate"><?= __('E-pošta odmah') ?></option>
                                    <option value="daily"><?= __('Dnevni sažetak') ?></option>
                                    <option value="important"><?= __('Samo važne promjene') ?></option>
                                </select>
                            </div>

                            <div class="table-responsive simbioza-user-follow-table-wrap">
                                <table class="table align-middle mb-0 simbioza-user-follow-table">
                                    <thead>
                                        <tr>
                                            <th scope="col"><?= __('Sadržaj') ?></th>
                                            <th scope="col" class="text-center text-nowrap"><?= __('Praćenje') ?></th>
                                            <th scope="col"><?= __('Dostava obavijesti') ?></th>
                                            <th scope="col" class="text-end"><span class="visually-hidden"><?= __('Otvori') ?></span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($follows as $item) : ?>
                                        <?php
                                        $type = is_string($item['target_type'] ?? null) ? $item['target_type'] : '';
                                        $targetId = is_scalar($item['target_id'] ?? null) ? (string)$item['target_id'] : '';
                                        $label = is_string($item['label'] ?? null) ? $item['label'] : __('Nedostupan sadržaj');
                                        $url = is_string($item['url'] ?? null) ? $item['url'] : '';
                                        $accessible = (bool)($item['accessible'] ?? false);
                                        $following = (bool)($item['following'] ?? false);
                                        $deliveryMode = is_string($item['delivery_mode'] ?? null)
                                            ? $item['delivery_mode']
                                            : '';
                                        $createdAt = is_scalar($item['created_at'] ?? null)
                                            ? (string)$item['created_at']
                                            : '';
                                        $documentId = is_scalar($item['document_id'] ?? null)
                                            ? (string)$item['document_id']
                                            : '';
                                        $state = $following ? 'following' : 'not-following';
                                        $controlBase = preg_replace(
                                            '/[^a-zA-Z0-9_-]+/',
                                            '-',
                                            'follow-mode-' . $type . '-' . $targetId,
                                        ) ?: 'follow-mode';
                                        ?>
                                        <tr data-simbioza-follow-item
                                            data-label="<?= $this->escape(mb_strtolower($label)) ?>"
                                            data-follow-state="<?= $this->escape($state) ?>"
                                            data-delivery-mode="<?= $this->escape($deliveryMode) ?>">
                                            <td data-label="<?= $this->escape(__('Sadržaj')) ?>">
                                                <span class="badge text-bg-light border mb-1">
                                                    <?= $this->escape($typeLabels[$type] ?? $type) ?>
                                                </span>
                                                <div class="fw-semibold text-break"><?= $this->escape($label) ?></div>
                                                <div class="small text-body-secondary">
                                                    <?php if ($following && $createdAt !== '') : ?>
                                                        <?= __('Prati se od') ?> <?= $this->escape($formatDateTime($createdAt)) ?>
                                                    <?php elseif ($type === 'calendar') : ?>
                                                        <?= __('Pretplaćeni ste na kalendar; obavijesti su isključene.') ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="text-center" data-label="<?= $this->escape(__('Praćenje')) ?>">
                                                <form method="post" action="<?= $this->escape((string)$togglePath) ?>" class="d-inline">
                                                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                                                    <input type="hidden" name="target_type" value="<?= $this->escape($type) ?>">
                                                    <input type="hidden" name="target_id" value="<?= $this->escape($targetId) ?>">
                                                    <input type="hidden" name="document_id" value="<?= $this->escape($documentId) ?>">
                                                    <input type="hidden" name="label" value="<?= $this->escape($label) ?>">
                                                    <input type="hidden" name="return_url"
                                                           value="<?= $this->escape((string)$profileFollowingPath) ?>">
                                                    <button type="submit"
                                                            class="btn btn-sm simbioza-user-icon-action <?= $following ? 'btn-primary is-active' : 'btn-secondary' ?>"
                                                            title="<?= $this->escape($following ? __('Prestani pratiti') : __('Prati')) ?>"
                                                            aria-label="<?= $this->escape($following ? __('Prestani pratiti') : __('Prati')) ?>">
                                                        <?= $icon('follow') ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td data-label="<?= $this->escape(__('Dostava obavijesti')) ?>"
                                                data-simbioza-delivery-cell>
                                                <form method="post" action="<?= $this->escape((string)$modePath) ?>"
                                                      class="simbioza-user-mode-form"
                                                      data-simbioza-mode-form
                                                      data-current-mode="<?= $this->escape($deliveryMode) ?>">
                                                    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                                                    <input type="hidden" name="target_type" value="<?= $this->escape($type) ?>">
                                                    <input type="hidden" name="target_id" value="<?= $this->escape($targetId) ?>">
                                                    <fieldset <?= $following ? '' : 'disabled' ?>>
                                                        <legend class="visually-hidden"><?= __('Način dostave') ?></legend>
                                                        <div class="simbioza-user-mode-layout">
                                                            <div class="simbioza-user-mode-options">
                                                        <?php foreach ($modeLabels as $mode => $modeLabel) : ?>
                                                            <?php $modeIcon = match ($mode) {
                                                                'immediate' => 'mail',
                                                                'daily' => 'daily',
                                                                'important' => 'important',
                                                                default => 'app',
                                                            }; ?>
                                                            <label class="simbioza-user-mode-choice"
                                                                   for="<?= $this->escape($controlBase . '-' . $mode) ?>"
                                                                   title="<?= $this->escape($modeLabel) ?>">
                                                                <input class="visually-hidden simbioza-user-mode-input" type="radio"
                                                                       id="<?= $this->escape($controlBase . '-' . $mode) ?>"
                                                                       name="email_mode_override"
                                                                       value="<?= $this->escape($mode) ?>"
                                                                       data-mode-description="<?= $this->escape($modeDescriptions[$mode]) ?>"
                                                                       <?= $deliveryMode === $mode ? 'checked' : '' ?>>
                                                                <span class="simbioza-user-mode-choice-content">
                                                                    <?= $icon($modeIcon) ?>
                                                                    <span class="visually-hidden"><?= $this->escape($modeLabel) ?></span>
                                                                </span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                            </div>
                                                            <p class="small text-body-secondary fst-italic simbioza-user-mode-description mb-0"
                                                               data-simbioza-mode-description>
                                                                <?= $following
                                                                    ? $this->escape($modeDescriptions[$deliveryMode] ?? $modeDescriptions['off'])
                                                                    : '—' ?>
                                                            </p>
                                                        </div>
                                                    </fieldset>
                                                    <span class="visually-hidden" role="status" aria-live="polite"
                                                          data-simbioza-mode-status></span>
                                                </form>
                                            </td>
                                            <td class="text-end" data-label="<?= $this->escape(__('Otvori')) ?>">
                                                <?php if ($accessible && $url !== '') : ?>
                                                    <a class="btn btn-sm btn-secondary simbioza-user-icon-action"
                                                       href="<?= $this->escape($url) ?>"
                                                       title="<?= $this->escape(__('Otvori')) ?>"
                                                       aria-label="<?= $this->escape(__('Otvori')) ?>">
                                                        <?= $icon('open') ?>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-body-secondary mb-0 mt-3" data-simbioza-follow-empty hidden>
                                <?= __('Nema stavki koje odgovaraju odabranom filtru.') ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toastTitle = <?= json_encode(__('Uspjeh'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const toastErrorTitle = <?= json_encode(__('Greška'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const genericError = <?= json_encode(__('Promjenu trenutačno nije moguće spremiti. Pokušajte ponovno.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const closeLabel = <?= json_encode(__('Zatvori'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    // HR: Dinamički toast koristi iste Bootstrap i tematske varijable kao ostale profilne poruke.
    // EN: The dynamic toast uses the same Bootstrap and theme variables as other profile messages.
    const showToast = function (message, level = 'success') {
        let container = document.querySelector('.simbioza-user-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3 simbioza-user-toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = 'toast simbioza-user-toast border-0 mb-2';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');

        const header = document.createElement('div');
        header.className = 'toast-header ' + (level === 'success' ? 'is-success' : 'is-danger');
        const title = document.createElement('strong');
        title.className = 'me-auto';
        title.textContent = level === 'success' ? toastTitle : toastErrorTitle;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('data-bs-dismiss', 'toast');
        close.setAttribute('aria-label', closeLabel);
        header.append(title, close);

        const body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message;
        toast.append(header, body);
        container.appendChild(toast);

        if (window.bootstrap && window.bootstrap.Toast) {
            window.bootstrap.Toast.getOrCreateInstance(toast, {autohide: true, delay: 7000}).show();
        } else {
            toast.classList.add('show');
            window.setTimeout(function () { toast.remove(); }, 7000);
        }
        toast.addEventListener('hidden.bs.toast', function () { toast.remove(); }, {once: true});
    };

    // HR: Šalje profilnu formu u pozadini bez promjene položaja ili stanja accordiona.
    // EN: Submits a profile form in the background without changing scroll or accordion state.
    const submitInBackground = async function (form) {
        const formData = new FormData(form);
        const selectedMode = form.querySelector('input[name="email_mode_override"]:checked');
        if (selectedMode instanceof HTMLInputElement) {
            formData.set('email_mode_override', selectedMode.value);
        }
        const response = await fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        let payload = {};
        try {
            payload = await response.json();
        } catch (error) {
            payload = {};
        }
        if (!response.ok || payload.ok !== true) {
            throw new Error(typeof payload.message === 'string' ? payload.message : genericError);
        }

        /*
         * HR: Middleware rotira CSRF nakon svakog POST-a. Novi token odmah
         *     prenosi svim formama kako sljedeća promjena ne bi tražila reload.
         * EN: Middleware rotates CSRF after every POST. Propagate the fresh
         *     token to every form so the next change never requires a reload.
         */
        if (typeof payload.csrf_token === 'string' && payload.csrf_token !== '') {
            const currentToken = form.querySelector('input[type="hidden"][name]');
            const tokenName = currentToken instanceof HTMLInputElement ? currentToken.name : '';
            if (tokenName !== '') {
                document.querySelectorAll('input[type="hidden"][name="' + CSS.escape(tokenName) + '"]').forEach(function (input) {
                    input.value = payload.csrf_token;
                });
            }
        }

        return payload;
    };

    const emailToggle = document.getElementById('simbioza-user-email-enabled');
    const emailModeSelect = document.getElementById('simbioza-user-email-mode');
    if (emailToggle && emailModeSelect) {
        emailToggle.addEventListener('change', function () {
            emailModeSelect.disabled = !emailToggle.checked;
            if (!emailToggle.checked) emailModeSelect.value = 'off';
        });
    }

    document.querySelector('[data-simbioza-preferences-form]')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!(form instanceof HTMLFormElement) || form.dataset.saving === '1') return;
        const submit = form.querySelector('[type="submit"]');
        form.dataset.saving = '1';
        if (submit instanceof HTMLButtonElement) submit.disabled = true;
        try {
            const payload = await submitInBackground(form);
            showToast(payload.message || toastTitle);
        } catch (error) {
            showToast(error instanceof Error ? error.message : genericError, 'danger');
        } finally {
            delete form.dataset.saving;
            if (submit instanceof HTMLButtonElement) submit.disabled = false;
        }
    });

    const search = document.querySelector('[data-simbioza-follow-search]');
    const filter = document.querySelector('[data-simbioza-follow-filter]');
    const empty = document.querySelector('[data-simbioza-follow-empty]');
    const applyFilters = function () {
        const needle = search ? search.value.trim().toLocaleLowerCase() : '';
        const selected = filter ? filter.value : 'all';
        let visible = 0;
        document.querySelectorAll('[data-simbioza-follow-item]').forEach(function (item) {
            const matchesText = needle === '' || String(item.dataset.label || '').includes(needle);
            const matchesState = selected === 'all'
                || item.dataset.followState === selected
                || item.dataset.deliveryMode === selected;
            item.hidden = !(matchesText && matchesState);
            if (!item.hidden) visible += 1;
        });
        if (empty) empty.hidden = visible > 0;
    };
    search?.addEventListener('input', applyFilters);
    filter?.addEventListener('change', applyFilters);

    document.querySelectorAll('[data-simbioza-mode-form]').forEach(function (form) {
        form.addEventListener('change', async function (event) {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || input.type !== 'radio' || form.dataset.saving === '1') {
                return;
            }

            const previousMode = form.dataset.currentMode || 'off';
            const previousInput = form.querySelector('input[value="' + CSS.escape(previousMode) + '"]');
            const row = form.closest('[data-simbioza-follow-item]');
            const status = form.querySelector('[data-simbioza-mode-status]');
            const description = form.querySelector('[data-simbioza-mode-description]');
            form.dataset.saving = '1';
            form.classList.add('is-saving');
            try {
                const payload = await submitInBackground(form);
                const mode = typeof payload.mode === 'string' ? payload.mode : input.value;
                const savedInput = form.querySelector('input[value="' + CSS.escape(mode) + '"]');
                if (savedInput instanceof HTMLInputElement) savedInput.checked = true;
                form.dataset.currentMode = mode;
                if (row instanceof HTMLElement) row.dataset.deliveryMode = mode;
                if (status) status.textContent = payload.message || '';
                if (description && savedInput instanceof HTMLInputElement) {
                    description.textContent = savedInput.dataset.modeDescription || '';
                }
                showToast(payload.message || toastTitle);
                applyFilters();
            } catch (error) {
                const previous = form.querySelector('input[value="' + CSS.escape(previousMode) + '"]');
                if (previous instanceof HTMLInputElement) previous.checked = true;
                if (description && previousInput instanceof HTMLInputElement) {
                    description.textContent = previousInput.dataset.modeDescription || '';
                }
                if (status) status.textContent = error instanceof Error ? error.message : genericError;
                showToast(error instanceof Error ? error.message : genericError, 'danger');
            } finally {
                delete form.dataset.saving;
                form.classList.remove('is-saving');
            }
        });
    });
});
</script>
