<?php
/**
 * Anzeige der Kennwortstaerke zu einem Kennwortfeld.
 *
 * Die Anzeige ist eine Hilfe beim Vergeben eines Kennworts und blockiert nichts: verbindlich
 * bleibt die Pruefung auf dem Server (Mindestlaenge und Ungleichheit zum Anmeldenamen).
 * Die Bewertung uebernimmt public/assets/js/app.js (kein Inline-JavaScript, CSP-konform);
 * ohne JavaScript bleibt der Hinweis auf die Mindestlaenge sichtbar.
 *
 * @var Closure $e
 * @var string $for Id des Kennwortfeldes
 * @var int $minimum Mindestlaenge des Kennworts
 */
?>
<div class="password-strength" data-password-strength="<?= $e($for) ?>" data-password-minimum="<?= $e($minimum) ?>">
    <div class="strength-bar-wrap"><div class="strength-bar" data-strength-bar></div></div>
    <p class="strength-label" data-strength-label>Kennwortstärke wird beim Tippen angezeigt.</p>
    <ul class="strength-rules">
        <li data-strength-rule="length" data-met="false">mindestens <?= $e($minimum) ?> Zeichen</li>
        <li data-strength-rule="upper" data-met="false">Großbuchstabe</li>
        <li data-strength-rule="lower" data-met="false">Kleinbuchstabe</li>
        <li data-strength-rule="digit" data-met="false">Ziffer</li>
        <li data-strength-rule="special" data-met="false">Sonderzeichen</li>
    </ul>
</div>
