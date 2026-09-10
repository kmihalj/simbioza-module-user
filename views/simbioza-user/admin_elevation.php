<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- HR/EN: Prevedeni HTML ostaje pregledan. / Translated HTML remains readable.

/**
 * @var \HeartPhrame\View\View $this
 * @var string $title
 * @var string $error
 * @var string $next
 * @var string $enablePath
 * @var string $cancelPath
 */
?>
<div class="row justify-content-center">
    <div class="col-lg-7 col-xl-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3"><?= $this->escape($title) ?></h1>

                <?php if ($error !== '') : ?>
                    <div class="alert alert-danger" role="alert"><?= $this->escape($error) ?></div>
                <?php endif; ?>

                <?php if ($enablePath !== '') : ?>
                    <p class="text-body-secondary">
                        <?= $this->escape(__('Za privremeno uključivanje administratorskih ovlasti potvrdite svoju lokalnu lozinku.')) ?>
                    </p>
                    <form method="post" action="<?= $this->escape($enablePath) ?>">
                        <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                        <input type="hidden" name="next" value="<?= $this->escape($next) ?>">
                        <div class="mb-4">
                            <label class="form-label" for="simbioza-admin-password"><?= $this->escape(__('Lokalna lozinka')) ?></label>
                            <input
                                class="form-control"
                                id="simbioza-admin-password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                required
                                autofocus
                            >
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary"><?= $this->escape(__('Uključi administratorske ovlasti')) ?></button>
                            <a class="btn btn-secondary" href="<?= $this->escape($cancelPath) ?>"><?= $this->escape(__('Odustani')) ?></a>
                        </div>
                    </form>
                <?php else : ?>
                    <a class="btn btn-secondary" href="<?= $this->escape($cancelPath) ?>"><?= $this->escape(__('Povratak')) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
