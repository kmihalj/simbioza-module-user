<?php

declare(strict_types=1);

/**
 * HR: Diskretni tematski gumb za uključivanje ili isključivanje praćenja.
 * EN: Discreet themed button for enabling or disabling a follow.
 *
 * @var \HeartPhrame\View\View $this
 * @var string $targetType
 * @var string $targetId
 * @var string $documentId
 * @var string $label
 * @var string $togglePath
 * @var string $statusPath
 * @var string $returnUrl
 * @var string $assetsCssPath
 * @var bool|string $compact
 */

$targetType = is_string($targetType ?? null) ? $targetType : '';
$targetId = is_string($targetId ?? null) ? $targetId : '';
$instanceId = 'simbioza-follow-' . substr(hash('sha256', $targetType . ':' . $targetId), 0, 12);
$compact = $compact === true || $compact === '1';
?>
<?php if (is_string($assetsCssPath ?? null) && $assetsCssPath !== '') : ?>
    <link rel="stylesheet" href="<?= $this->escape($assetsCssPath) ?>">
<?php endif; ?>
<form class="simbioza-follow-button-form<?= $compact
    ? ' simbioza-follow-button-form--toolbar'
    : '' ?>" method="post" action="<?= $this->escape((string)$togglePath) ?>"
      id="<?= $this->escape($instanceId) ?>"
      data-follow-status-url="<?= $this->escape((string)$statusPath) ?>">
    <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
    <input type="hidden" name="target_type" value="<?= $this->escape($targetType) ?>">
    <input type="hidden" name="target_id" value="<?= $this->escape($targetId) ?>">
    <input type="hidden" name="document_id" value="<?= $this->escape((string)($documentId ?? '')) ?>">
    <input type="hidden" name="label" value="<?= $this->escape((string)($label ?? '')) ?>">
    <input type="hidden" name="return_url" value="<?= $this->escape((string)($returnUrl ?? '')) ?>">
    <button class="btn btn-outline-secondary btn-sm simbioza-follow-button<?= $compact
        ? ' simbioza-follow-button--compact editor-html-view-action'
        : '' ?>" type="submit"
            data-follow-button
            data-follow-label="<?= $this->escape(__('Prati')) ?>"
            data-unfollow-label="<?= $this->escape(__('Prestani pratiti')) ?>"
            data-follow-title="<?= $this->escape(__('Prati promjene ovog sadržaja')) ?>"
            data-unfollow-title="<?= $this->escape(__('Prestani pratiti promjene ovog sadržaja')) ?>"
            title="<?= $this->escape(__('Prati promjene ovog sadržaja')) ?>"
            aria-label="<?= $this->escape(__('Prati promjene ovog sadržaja')) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z"/>
            <path d="M10 21h4"/>
        </svg>
        <span data-follow-button-label><?= __('Prati') ?></span>
    </button>
</form>
<script>
(function () {
    const form = document.getElementById(<?= json_encode(
        $instanceId,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
    ) ?>);
    if (!(form instanceof HTMLFormElement)) return;
    const button = form.querySelector('[data-follow-button]');
    const label = form.querySelector('[data-follow-button-label]');
    const type = form.querySelector('[name="target_type"]');
    const id = form.querySelector('[name="target_id"]');
    if (!(button instanceof HTMLButtonElement) || !(label instanceof HTMLElement)
        || !(type instanceof HTMLInputElement) || !(id instanceof HTMLInputElement)) return;
    const url = new URL(form.dataset.followStatusUrl || '', window.location.origin);
    url.searchParams.set('target_type', type.value);
    url.searchParams.set('target_id', id.value);
    fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
        .then((response) => response.ok ? response.json() : null)
        .then((payload) => {
            if (!payload) return;
            const following = payload.following === true;
            label.textContent = following ? button.dataset.unfollowLabel : button.dataset.followLabel;
            button.classList.toggle('is-following', following);
            button.setAttribute('aria-pressed', following ? 'true' : 'false');
            const title = following ? button.dataset.unfollowTitle : button.dataset.followTitle;
            if (title) {
                button.title = title;
                button.setAttribute('aria-label', title);
            }
        })
        .catch(() => {});
})();
</script>
