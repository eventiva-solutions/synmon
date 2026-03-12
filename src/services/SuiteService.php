<?php

namespace eventiva\synmon\services;

use Craft;
use craft\helpers\StringHelper;
use eventiva\synmon\records\StepRecord;
use eventiva\synmon\records\SuiteRecord;
use yii\base\Component;

class SuiteService extends Component
{
    public function getSuites(): array
    {
        return SuiteRecord::find()->orderBy('name ASC')->asArray()->all();
    }

    public function getSuiteById(int $id): ?array
    {
        $record = SuiteRecord::findOne($id);
        return $record ? $record->toArray() : null;
    }

    public function createSuite(array $data): int|false
    {
        $record = new SuiteRecord();
        $record->uid              = StringHelper::UUID();
        $record->name             = $data['name'] ?? 'Neue Suite';
        $record->description      = $data['description'] ?? null;
        $record->cronExpression   = $data['cronExpression'] ?? '*/5 * * * *';
        $record->enabled          = (bool)($data['enabled'] ?? true);
        $record->notifyEmail      = $data['notifyEmail'] ?? null;
        $record->notifyWebhookUrl = $data['notifyWebhookUrl'] ?? null;
        $record->notifyOnSuccess  = (bool)($data['notifyOnSuccess'] ?? false);

        if ($record->save()) {
            return $record->id;
        }

        Craft::error('SuiteService::createSuite failed: ' . json_encode($record->errors), __METHOD__);
        return false;
    }

    public function updateSuite(int $id, array $data): bool
    {
        $record = SuiteRecord::findOne($id);
        if (!$record) {
            return false;
        }

        $record->name             = $data['name'] ?? $record->name;
        $record->description      = $data['description'] ?? $record->description;
        $record->cronExpression   = $data['cronExpression'] ?? $record->cronExpression;
        $record->enabled          = (bool)($data['enabled'] ?? $record->enabled);
        $record->notifyEmail      = $data['notifyEmail'] ?? $record->notifyEmail;
        $record->notifyWebhookUrl = $data['notifyWebhookUrl'] ?? $record->notifyWebhookUrl;
        $record->notifyOnSuccess  = (bool)($data['notifyOnSuccess'] ?? $record->notifyOnSuccess);

        if ($record->save()) {
            return true;
        }

        Craft::error('SuiteService::updateSuite failed: ' . json_encode($record->errors), __METHOD__);
        return false;
    }

    public function deleteSuite(int $id): bool
    {
        $record = SuiteRecord::findOne($id);
        if (!$record) {
            return false;
        }
        return (bool)$record->delete();
    }

    public function getStepsBySuiteId(int $suiteId): array
    {
        return StepRecord::find()
            ->where(['suiteId' => $suiteId])
            ->orderBy('sortOrder ASC')
            ->asArray()
            ->all();
    }

    public function saveSteps(int $suiteId, array $steps): void
    {
        StepRecord::deleteAll(['suiteId' => $suiteId]);

        foreach ($steps as $index => $stepData) {
            $record              = new StepRecord();
            $record->uid         = StringHelper::UUID();
            $record->suiteId     = $suiteId;
            $record->sortOrder   = (int)($stepData['sortOrder'] ?? $index);
            $record->type        = $stepData['type'] ?? 'navigate';
            $record->selector    = $stepData['selector'] ?? null;
            $record->value       = $stepData['value'] ?? null;
            $record->description = $stepData['description'] ?? null;
            $record->timeout     = (int)($stepData['timeout'] ?? 30000);
            $record->save();
        }
    }

    public function cloneSuite(int $id): int|false
    {
        $source = SuiteRecord::findOne($id);
        if (!$source) {
            return false;
        }

        $clone              = new SuiteRecord();
        $clone->uid         = StringHelper::UUID();
        $clone->name        = $source->name . ' (Kopie)';
        $clone->description = $source->description;
        $clone->cronExpression   = $source->cronExpression;
        $clone->enabled          = false; // disabled by default so cron doesn't run it immediately
        $clone->notifyEmail      = $source->notifyEmail;
        $clone->notifyWebhookUrl = $source->notifyWebhookUrl;
        $clone->notifyOnSuccess  = $source->notifyOnSuccess;

        if (!$clone->save()) {
            return false;
        }

        $steps = StepRecord::find()->where(['suiteId' => $id])->orderBy('sortOrder ASC')->all();
        foreach ($steps as $step) {
            $newStep              = new StepRecord();
            $newStep->uid         = StringHelper::UUID();
            $newStep->suiteId     = $clone->id;
            $newStep->sortOrder   = $step->sortOrder;
            $newStep->type        = $step->type;
            $newStep->selector    = $step->selector;
            $newStep->value       = $step->value;
            $newStep->description = $step->description;
            $newStep->timeout     = $step->timeout;
            $newStep->save();
        }

        return $clone->id;
    }

    public function updateLastRunStatus(int $suiteId, string $status): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%synmon_suites}}',
            [
                'lastRunAt'     => (new \DateTime())->format('Y-m-d H:i:s'),
                'lastRunStatus' => $status,
                'dateUpdated'   => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
            ['id' => $suiteId]
        )->execute();
    }

    public function getStepTypes(): array
    {
        $selectorTips = '<br><br><b>Selector-Tipps (allgemein):</b><br>'
            . '• <code>#mein-id</code> → Element mit ID<br>'
            . '• <code>.meine-klasse</code> → Element mit Klasse<br>'
            . '• <code>input[name="vorname"]</code> → Attribut-Selektor<br>'
            . '• <code>input[name="felder[]"][value="Option"]</code> → Checkbox mit bestimmtem Wert<br>'
            . '• <code>form .btn-primary</code> → Kind-Element (Leerzeichen)<br>'
            . '• <code>h2 + p</code> → direkt folgendes Geschwister-Element<br>'
            . '• <code>li:first-child</code> / <code>li:last-child</code> → erstes/letztes Kind<br>'
            . '• <code>p:nth-of-type(2)</code> → 2. Element dieses Typs<br>'
            . '• <code>[data-fui-id*="kontakt"]</code> → Attribut enthält Wert (nützlich bei dynamischen IDs)';

        return [
            'navigate' => [
                'label' => 'Navigate', 'hasSelector' => false, 'hasValue' => true,
                'valuePlaceholder' => 'https://example.com/kontakt',
                'hint' => 'Ruft eine URL im Browser auf und wartet bis die Seite vollständig geladen ist.<br><b>Wert:</b> Vollständige URL inkl. <code>https://</code><br><b>Tipp:</b> Jeder Test sollte mit einem Navigate-Step beginnen.',
            ],
            'click' => [
                'label' => 'Click', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => 'button[type="submit"]',
                'hint' => 'Klickt auf ein Element.<br><b>Selector:</b> CSS-Selector des Elements<br><b>Beispiele:</b><br>• <code>button[type="submit"]</code> → Absende-Button<br>• <code>#nav-kontakt</code> → Link mit ID<br>• <code>.btn-primary</code> → Button mit Klasse<br>• <code>a[href="/kontakt"]</code> → Link auf bestimmte URL<br>• <code>nav li:last-child a</code> → Letzter Nav-Link' . $selectorTips,
            ],
            'fill' => [
                'label' => 'Fill Input', 'hasSelector' => true, 'hasValue' => true,
                'selectorPlaceholder' => 'input[name="fields[vorname]"]',
                'valuePlaceholder' => 'Max Mustermann',
                'hint' => 'Trägt Text in ein Eingabefeld ein (überschreibt vorhandenen Inhalt).<br><b>Selector:</b> CSS-Selector des Eingabefelds<br><b>Beispiele:</b><br>• <code>input[name="fields[vorname]"]</code> → Craft Freeform Feld<br>• <code>input[type="email"]</code> → E-Mail-Feld<br>• <code>textarea[name="fields[nachricht]"]</code> → Textbereich<br>• <code>#search-input</code> → Suchfeld mit ID<br><b>Wert:</b> Der einzugebende Text' . $selectorTips,
            ],
            'select' => [
                'label' => 'Select Option', 'hasSelector' => true, 'hasValue' => true,
                'selectorPlaceholder' => 'select[name="fields[anrede]"]',
                'valuePlaceholder' => 'Herr',
                'hint' => 'Wählt eine Option in einem <code>&lt;select&gt;</code>-Dropdown.<br><b>Selector:</b> CSS-Selector des <code>&lt;select&gt;</code>-Elements<br><b>Beispiele:</b><br>• <code>select[name="fields[anrede]"]</code> → Craft Freeform Select<br>• <code>select#land</code> → Select mit ID<br><b>Wert:</b> Das <code>value</code>-Attribut der Option – <i>nicht</i> der angezeigte Text' . $selectorTips,
            ],
            'pressKey' => [
                'label' => 'Press Key', 'hasSelector' => false, 'hasValue' => true,
                'valuePlaceholder' => 'Enter',
                'hint' => 'Drückt eine Taste auf der Tastatur (wirkt auf das aktuell fokussierte Element).<br><b>Wert:</b> <code>Enter</code>, <code>Tab</code>, <code>Escape</code>, <code>Space</code>, <code>Backspace</code>, <code>Delete</code>, <code>ArrowDown</code>, <code>ArrowUp</code>, <code>ArrowLeft</code>, <code>ArrowRight</code><br><b>Tipp:</b> Nach einem <code>fill</code>-Step kann <code>Enter</code> ein Formular absenden.',
            ],
            'assertVisible' => [
                'label' => 'Assert Visible', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => '.success-message',
                'hint' => 'Prüft ob ein Element sichtbar ist. Schlägt fehl wenn es nicht existiert oder per CSS versteckt ist.<br><b>Beispiele:</b><br>• <code>.success-message</code> → Erfolgsmeldung nach Formular-Submit<br>• <code>#cookie-banner</code> → Cookie-Banner sichtbar<br>• <code>.product-grid .item</code> → Mind. ein Produkt vorhanden<br><b>Tipp:</b> Nützlich um nach Klick/Submit zu prüfen ob eine Meldung oder ein Bereich erscheint' . $selectorTips,
            ],
            'assertText' => [
                'label' => 'Assert Text', 'hasSelector' => true, 'hasValue' => true,
                'selectorPlaceholder' => '.success-message, h1, #ausgabe',
                'valuePlaceholder' => 'Vielen Dank (Teilstring reicht)',
                'hint' => 'Wartet bis ein Element den erwarteten Text enthält (prüft alle 250ms bis Timeout, inkl. Shadow DOM).<br><b>Selector:</b> Bei mehreren Treffern reicht es wenn <i>irgendein</i> Element den Text enthält<br><b>Wert:</b> Gesuchter Textinhalt (Teilstring, Groß-/Kleinschreibung beachten)<br><b>Beispiele:</b><br>• <code>.fui-alert</code> → Freeform Erfolgs-/Fehlermeldung<br>• <code>h1</code> → Seitenüberschrift<br>• <code>.message:last-child p</code> → letzter Paragraph der letzten Message<br>• <code>.message p:nth-of-type(2)</code> → genau der 2. Paragraph<br><b>⚠️ Wenn "Text auf Seite sichtbar aber nicht gefunden":</b> Selector zu eng → breiter wählen, z.B. <code>.message</code> statt <code>.message span</code>' . $selectorTips,
            ],
            'assertUrl' => [
                'label' => 'Assert URL', 'hasSelector' => false, 'hasValue' => true,
                'valuePlaceholder' => '/danke oder ?success=1',
                'hint' => 'Prüft ob die aktuelle Seiten-URL einen bestimmten Text enthält.<br><b>Wert:</b> Gesuchter URL-Substring<br><b>Beispiele:</b><br>• <code>/danke</code> → Weiterleitung nach Formular-Submit<br>• <code>/success</code> → Erfolgsseite<br>• <code>?status=ok</code> → Query-Parameter<br>• <code>amr-eventtechnik.de/kontakt</code> → Exakte Domain + Pfad',
            ],
            'assertTitle' => [
                'label' => 'Assert Title', 'hasSelector' => false, 'hasValue' => true,
                'valuePlaceholder' => 'Kontakt – AMR Eventtechnik',
                'hint' => 'Prüft ob der <code>&lt;title&gt;</code>-Tag der Seite einen bestimmten Text enthält.<br><b>Wert:</b> Gesuchter Teilstring des Seitentitels<br><b>Tipp:</b> Nützlich um sicherzustellen dass die richtige Seite geladen wurde.',
            ],
            'waitForSelector' => [
                'label' => 'Wait for Selector', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => '.search-results, #chat-widget',
                'hint' => 'Wartet bis ein Element im DOM erscheint und sichtbar ist.<br><b>Beispiele:</b><br>• <code>.search-results</code> → Suchergebnisse nach AJAX-Laden<br>• <code>#chat-widget</code> → Widget erscheint nach Verzögerung<br>• <code>.fui-form</code> → Freeform Formular geladen<br><b>Tipp:</b> Timeout erhöhen wenn Inhalte lange zum Laden brauchen' . $selectorTips,
            ],
            'assertNotVisible' => [
                'label' => 'Assert Not Visible', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => '.modal, #cookie-banner, .spinner',
                'hint' => 'Prüft ob ein Element <b>nicht sichtbar</b> ist oder aus dem DOM entfernt wurde.<br><b>Beispiele:</b><br>• <code>.modal</code> → Modal wurde geschlossen<br>• <code>#cookie-banner</code> → Banner nach Akzeptieren weg<br>• <code>.loading-spinner</code> → Ladeanimation beendet<br>• <code>.error-message</code> → Kein Fehler vorhanden' . $selectorTips,
            ],
            'hover' => [
                'label' => 'Hover', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => 'nav .has-dropdown',
                'hint' => 'Bewegt die Maus über ein Element (Hover-Effekt).<br><b>Beispiele:</b><br>• <code>nav .has-dropdown</code> → Dropdown-Navigation öffnen<br>• <code>.product-card:first-child</code> → Hover auf erstes Produkt<br>• <code>[data-tooltip]</code> → Tooltip-Element<br><b>Tipp:</b> Nach Hover direkt <code>waitForSelector</code> für das erscheinende Element setzen' . $selectorTips,
            ],
            'scroll' => [
                'label' => 'Scroll', 'hasSelector' => true, 'hasValue' => true,
                'selectorPlaceholder' => '#kontakt-formular (optional)',
                'valuePlaceholder' => '500 (Pixel, negativ = hoch)',
                'hint' => 'Scrollt die Seite oder bringt ein Element in den Sichtbereich.<br><b>Selector (optional):</b> Element das in den Sichtbereich gescrollt werden soll<br><b>Wert (ohne Selector):</b> Pixel, z.B. <code>500</code> (runter), <code>-200</code> (hoch)<br><b>Beispiele:</b><br>• Selector <code>#kontakt-formular</code> → scrollt zum Formular<br>• Selector <code>footer</code> → scrollt zum Footer<br>• Nur Wert <code>800</code> → scrollt 800px nach unten<br><b>Tipp:</b> Lazy-loaded Bilder/Inhalte werden erst nach dem Scrollen geladen' . $selectorTips,
            ],
            'waitMs' => [
                'label' => 'Wait (ms)', 'hasSelector' => false, 'hasValue' => true,
                'valuePlaceholder' => '1000',
                'hint' => 'Wartet eine feste Anzahl Millisekunden.<br><b>Wert:</b> Wartezeit in ms, z.B. <code>500</code> = 0,5s · <code>1000</code> = 1s · <code>3000</code> = 3s<br><b>⚠️ Tipp:</b> Möglichst <code>waitForSelector</code> oder <code>assertText</code> bevorzugen – feste Wartezeiten sind fragil bei wechselnder Servergeschwindigkeit.',
            ],
            'assertCount' => [
                'label' => 'Assert Count', 'hasSelector' => true, 'hasValue' => true,
                'selectorPlaceholder' => '.product-card, li.result',
                'valuePlaceholder' => '3',
                'hint' => 'Prüft ob genau N Elemente dem Selector entsprechen.<br><b>Selector:</b> CSS-Selector<br><b>Wert:</b> Erwartete Anzahl (exakt)<br><b>Beispiele:</b><br>• <code>.nav-item</code> + <code>5</code> → genau 5 Navigationspunkte<br>• <code>.product-card</code> + <code>12</code> → 12 Produkte geladen<br>• <code>table tbody tr</code> + <code>10</code> → 10 Tabellenzeilen' . $selectorTips,
            ],
            'check' => [
                'label' => 'Checkbox aktivieren', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => 'input[name="fields[agb]"]',
                'hint' => 'Setzt eine Checkbox oder Radio-Button auf <b>aktiviert</b> (funktioniert auch bei styled Checkboxen mit Label-Overlay).<br><b>Beispiele:</b><br>• <code>input[name="fields[agb]"]</code> → AGB-Checkbox (Craft Freeform)<br>• <code>input[name="fields[technik][]"][value="Audiotechnik"]</code> → Checkbox-Gruppe mit Wert<br>• <code>input[type="radio"][value="ja"]</code> → Radio-Button<br>• <code>[data-fui-id*="newsletter"]</code> → Element mit dynamischer ID (enthält "newsletter")' . $selectorTips,
            ],
            'uncheck' => [
                'label' => 'Checkbox deaktivieren', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => 'input[name="fields[newsletter]"]',
                'hint' => 'Setzt eine Checkbox auf <b>deaktiviert</b>.<br><b>Beispiele:</b><br>• <code>input[name="fields[newsletter]"]</code> → Newsletter-Opt-in<br>• <code>input[name="fields[technik][]"][value="Audiotechnik"]</code> → Checkbox-Gruppe mit Wert' . $selectorTips,
            ],
        ];
    }
}
