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
        return [
            'navigate' => [
                'label' => 'Navigate', 'hasSelector' => false, 'hasValue' => true,
                'valuePlaceholder' => 'https://example.com',
                'hint' => 'Ruft eine URL im Browser auf und wartet bis die Seite vollständig geladen ist.<br><b>Wert:</b> Vollständige URL inkl. <code>https://</code>',
            ],
            'click' => [
                'label' => 'Click', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => '#id oder .klasse',
                'hint' => 'Klickt auf ein Element.<br><b>Selector:</b> CSS-Selector, z.B. <code>#submit-btn</code>, <code>.btn-primary</code>, <code>button[type="submit"]</code>, <code>a[href="/kontakt"]</code>',
            ],
            'fill' => [
                'label' => 'Fill Input', 'hasSelector' => true, 'hasValue' => true,
                'selectorPlaceholder' => '#input oder input[name="q"]',
                'valuePlaceholder' => 'Einzugebender Text',
                'hint' => 'Trägt Text in ein Eingabefeld ein (überschreibt vorhandenen Inhalt).<br><b>Selector:</b> CSS-Selector des Eingabefelds, z.B. <code>input[type="email"]</code>, <code>textarea#nachricht</code><br><b>Wert:</b> Der einzugebende Text',
            ],
            'select' => [
                'label' => 'Select Option', 'hasSelector' => true, 'hasValue' => true,
                'selectorPlaceholder' => 'select#feld',
                'valuePlaceholder' => 'option-value',
                'hint' => 'Wählt eine Option in einem <code>&lt;select&gt;</code>-Dropdown.<br><b>Selector:</b> CSS-Selector des <code>&lt;select&gt;</code>-Elements<br><b>Wert:</b> Das <code>value</code>-Attribut der Option – <i>nicht</i> der angezeigte Text',
            ],
            'pressKey' => [
                'label' => 'Press Key', 'hasSelector' => false, 'hasValue' => true,
                'valuePlaceholder' => 'Enter, Tab, Escape …',
                'hint' => 'Drückt eine Taste auf der Tastatur.<br><b>Wert:</b> <code>Enter</code>, <code>Tab</code>, <code>Escape</code>, <code>Space</code>, <code>Backspace</code>, <code>ArrowDown</code>, <code>ArrowUp</code>',
            ],
            'assertVisible' => [
                'label' => 'Assert Visible', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => '.element oder #id',
                'hint' => 'Prüft ob ein Element sichtbar ist. Schlägt fehl wenn es nicht existiert oder per CSS versteckt ist.<br><b>Selector:</b> CSS-Selector des Elements<br><b>Tipp:</b> Nützlich um nach einer Aktion zu prüfen ob ein Ergebnis, eine Meldung oder ein Bereich erscheint',
            ],
            'assertText' => [
                'label' => 'Assert Text', 'hasSelector' => true, 'hasValue' => true,
                'selectorPlaceholder' => 'h1, .ergebnis p, #ausgabe',
                'valuePlaceholder' => 'Erwarteter Text (Teilstring reicht)',
                'hint' => 'Wartet bis ein Element den erwarteten Text enthält (prüft alle 250ms bis Timeout, inkl. Shadow DOM).<br><b>Selector:</b> Volles CSS3 – bei mehreren Treffern reicht es wenn <i>irgendein</i> Element den Text enthält<br><b>Wert:</b> Gesuchter Textinhalt (Teilstring, Groß-/Kleinschreibung beachten)<br><b>Nützliche Selektoren:</b><br>• <code>.message p:last-of-type</code> → letzter &lt;p&gt; einer Message<br>• <code>.message p:nth-of-type(2)</code> → genau der 2. Paragraph<br>• <code>.message:last-child p</code> → alle &lt;p&gt; in der letzten Message<br>• <code>.message</code> → gesamter Inhalt (breiter, findet Text in allen Kind-Elementen)<br><b>⚠️ Fehlermeldung:</b> Wenn "Text ist auf der Seite sichtbar" angezeigt wird → Selector ist zu eng, nicht das Timing',
            ],
            'assertUrl' => [
                'label' => 'Assert URL', 'hasSelector' => false, 'hasValue' => true,
                'valuePlaceholder' => '/danke oder ?success=1',
                'hint' => 'Prüft ob die aktuelle Seiten-URL einen bestimmten Text enthält.<br><b>Wert:</b> Gesuchter URL-Substring, z.B. <code>/danke</code>, <code>/success</code>, <code>?status=ok</code>',
            ],
            'assertTitle' => [
                'label' => 'Assert Title', 'hasSelector' => false, 'hasValue' => true,
                'valuePlaceholder' => 'Erwarteter Seitentitel',
                'hint' => 'Prüft ob der <code>&lt;title&gt;</code>-Tag der Seite einen bestimmten Text enthält.<br><b>Wert:</b> Gesuchter Teilstring des Seitentitels',
            ],
            'waitForSelector' => [
                'label' => 'Wait for Selector', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => '.ergebnis oder #ausgabe',
                'hint' => 'Wartet bis ein Element im DOM erscheint und sichtbar ist.<br><b>Selector:</b> CSS-Selector des erwarteten Elements<br><b>Tipp:</b> Sinnvoll nach Klicks oder Formular-Submits wenn Inhalte dynamisch nachgeladen werden · Timeout anpassen wenn das Laden länger dauert',
            ],
            'assertNotVisible' => [
                'label' => 'Assert Not Visible', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => '.modal, .overlay, #spinner',
                'hint' => 'Prüft ob ein Element <b>nicht sichtbar</b> ist oder aus dem DOM entfernt wurde.<br><b>Selector:</b> CSS-Selector des Elements<br><b>Tipp:</b> Nützlich um sicherzustellen dass Ladeanimationen, Modals oder Fehlermeldungen verschwunden sind',
            ],
            'hover' => [
                'label' => 'Hover', 'hasSelector' => true, 'hasValue' => false,
                'selectorPlaceholder' => 'nav .dropdown, .tooltip-trigger',
                'hint' => 'Bewegt die Maus über ein Element (Hover-Effekt).<br><b>Selector:</b> CSS-Selector des Elements<br><b>Tipp:</b> Nützlich um Dropdown-Menüs oder Tooltips zu öffnen die erst beim Hover erscheinen',
            ],
            'scroll' => [
                'label' => 'Scroll', 'hasSelector' => true, 'hasValue' => true,
                'selectorPlaceholder' => '.section (optional)',
                'valuePlaceholder' => '500 (Pixel, negativ = hoch)',
                'hint' => 'Scrollt die Seite oder bringt ein Element in den Sichtbereich.<br><b>Selector (optional):</b> Element das in den Sichtbereich gescrollt werden soll<br><b>Wert (optional, ohne Selector):</b> Pixel-Anzahl zum Scrollen, z.B. <code>500</code> (runter), <code>-200</code> (hoch)<br><b>Tipp:</b> Lazy-loaded Inhalte werden erst sichtbar wenn sie gescrollt werden',
            ],
            'waitMs' => [
                'label' => 'Wait (ms)', 'hasSelector' => false, 'hasValue' => true,
                'valuePlaceholder' => '2000',
                'hint' => 'Wartet eine feste Anzahl Millisekunden.<br><b>Wert:</b> Wartezeit in ms, z.B. <code>1000</code> = 1 Sekunde<br><b>⚠️ Tipp:</b> Lieber <code>waitForSelector</code> oder <code>assertText</code> nutzen wenn möglich – feste Wartezeiten sind fragil bei unterschiedlichen Servergeschwindigkeiten',
            ],
            'assertCount' => [
                'label' => 'Assert Count', 'hasSelector' => true, 'hasValue' => true,
                'selectorPlaceholder' => 'li.product, .result-item',
                'valuePlaceholder' => '3',
                'hint' => 'Prüft ob genau N Elemente dem Selector entsprechen.<br><b>Selector:</b> CSS-Selector<br><b>Wert:</b> Erwartete Anzahl Elemente (exakt)<br><b>Tipp:</b> Nützlich um sicherzustellen dass z.B. genau 3 Suchergebnisse oder alle 5 Navigationslinks vorhanden sind',
            ],
        ];
    }
}
