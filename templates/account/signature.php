<?php
/**
 * Eigene Unterschrift hinterlegen oder entfernen. Steht jeder angemeldeten Person offen.
 *
 * Die Unterschrift erscheint im Brief unter der Grussformel; darunter steht immer der Name aus
 * Titel, Vorname und Nachname.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var Closure(string): bool $permitted
 * @var \App\User\User $user
 * @var array{id:int,sha256:string,mime_type:string,filename:string,width:int,height:int,created_at:string}|null $signature
 * @var string $signerName
 * @var array<string, string> $errors
 * @var array{bytes:int,dimension:int} $limits
 */
$hasSignature = $signature !== null;
?>
<div class="page-head">
    <div>
        <h1><?= $icon('edit', 'app-icon app-icon--lg') ?><span>Unterschrift</span></h1>
        <p class="lead">Die Unterschrift erscheint im Brief unter der Grußformel – darunter immer
            Titel, Vorname und Name.</p>
    </div>
    <div class="actions">
        <a class="button" href="/"><?= $icon('back') ?> <span>Zum Dashboard</span></a>
        <?php if ($permitted('/system/users')): ?>
            <a class="button" href="/system/users/<?= $e($user->id) ?>/edit"><?= $icon('user') ?> <span>Mein Benutzerkonto</span></a>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($errors['signature'])): ?>
    <p class="alert alert-error" role="alert"><?= $e($errors['signature']) ?></p>
<?php endif; ?>

<form method="post" action="/account/signature" class="card" enctype="multipart/form-data">
    <?= $csrf() ?>

    <h2><?= $icon('edit', 'app-icon app-icon--sm') ?> Hinterlegte Unterschrift</h2>

    <?php if ($hasSignature): ?>
        <p class="signature-preview">
            <img src="/account/signature/image?v=<?= $e(substr((string) $signature['sha256'], 0, 12)) ?>"
                 alt="Meine Unterschrift" class="signature-image">
        </p>
        <p class="muted"><?= $e($signature['filename']) ?> ·
            <?= $e($signature['width']) ?> × <?= $e($signature['height']) ?> Pixel ·
            hinterlegt am <?= $e(substr((string) $signature['created_at'], 0, 10)) ?></p>
    <?php else: ?>
        <p class="muted">Es ist noch keine Unterschrift hinterlegt. Ohne Unterschrift bleibt im Brief
            an dieser Stelle Platz für die handschriftliche Unterschrift.</p>
    <?php endif; ?>

    <div class="field">
        <label for="signature"><?= $hasSignature ? 'Neue Unterschrift auswählen' : 'Unterschrift auswählen' ?></label>
        <input type="file" id="signature" name="signature" accept="image/png,image/jpeg,image/bmp">
        <p class="muted">JPG, PNG oder BMP, höchstens
            <?= $e(\App\Security\UploadValidator::formatBytes($limits['bytes'])) ?>,
            maximal <?= $e($limits['dimension']) ?> × <?= $e($limits['dimension']) ?> Pixel. Die Anpassung an
            die Größe im Brief erfolgt automatisch; die Datei wird unverändert aufbewahrt.</p>
    </div>

    <?php if ($hasSignature): ?>
        <label class="check">
            <input type="checkbox" name="remove_signature" value="1">
            <span>Hinterlegte Unterschrift entfernen</span>
        </label>
    <?php endif; ?>

    <p class="muted">Unter der Unterschrift steht im Brief immer: <strong><?= $e($signerName) ?></strong>.
        Der Name stammt aus Titel, Vorname und Nachname
        <?php if ($permitted('/system/users')): ?>
            und wird in der <a href="/system/users/<?= $e($user->id) ?>/edit">Benutzerverwaltung</a> gepflegt.
        <?php else: ?>
            und wird von der Administration gepflegt.
        <?php endif; ?>
    </p>

    <div class="form-actions">
        <button type="submit" class="primary" data-once>
            <?= $icon('check', 'app-icon app-icon--sm') ?> <span data-label>Unterschrift speichern</span>
        </button>
    </div>
</form>
