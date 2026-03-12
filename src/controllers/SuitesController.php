<?php

namespace eventiva\synmon\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use eventiva\synmon\records\RunRecord;
use eventiva\synmon\SynMon;
use eventiva\synmon\jobs\RunSuiteJob;

class SuitesController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireAdmin();
        return true;
    }

    public function actionIndex(): \yii\web\Response
    {
        $suites    = SynMon::getInstance()->getSuiteService()->getSuites();
        $scheduler = SynMon::getInstance()->getSchedulerService();

        $nextRuns = [];
        foreach ($suites as $suite) {
            if ($suite['enabled']) {
                $next = $scheduler->getNextRunTime($suite['cronExpression']);
                $nextRuns[$suite['id']] = $next ? $next->format('d.m.Y H:i') : null;
            }
        }

        return $this->renderTemplate('synmon/cp/suites/index', [
            'title'    => 'Test Suites',
            'suites'   => $suites,
            'nextRuns' => $nextRuns,
        ]);
    }

    public function actionNew(): \yii\web\Response
    {
        return $this->renderTemplate('synmon/cp/suites/_edit', [
            'title'     => 'Neue Suite',
            'suite'     => null,
            'steps'     => [],
            'stepTypes' => SynMon::getInstance()->getSuiteService()->getStepTypes(),
        ]);
    }

    public function actionEdit(int $id): \yii\web\Response
    {
        $suite = SynMon::getInstance()->getSuiteService()->getSuiteById($id);
        if (!$suite) {
            throw new \yii\web\NotFoundHttpException("Suite #{$id} not found.");
        }

        $steps = SynMon::getInstance()->getSuiteService()->getStepsBySuiteId($id);

        return $this->renderTemplate('synmon/cp/suites/_edit', [
            'title'     => 'Suite bearbeiten: ' . $suite['name'],
            'suite'     => $suite,
            'steps'     => $steps,
            'stepTypes' => SynMon::getInstance()->getSuiteService()->getStepTypes(),
        ]);
    }

    public function actionLive(int $id): \yii\web\Response
    {
        $suite = SynMon::getInstance()->getSuiteService()->getSuiteById($id);
        if (!$suite) {
            throw new \yii\web\NotFoundHttpException("Suite #{$id} not found.");
        }

        $steps = SynMon::getInstance()->getSuiteService()->getStepsBySuiteId($id);

        return $this->renderTemplate('synmon/cp/suites/_live', [
            'title'     => 'Live Test: ' . $suite['name'],
            'suite'     => $suite,
            'steps'     => $steps,
            'stepTypes' => SynMon::getInstance()->getSuiteService()->getStepTypes(),
        ]);
    }

    public function actionSave(): \yii\web\Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $id   = $request->getBodyParam('suiteId');
        $data = [
            'name'             => $request->getBodyParam('name'),
            'description'      => $request->getBodyParam('description'),
            'cronExpression'   => $request->getBodyParam('cronExpression', '*/5 * * * *'),
            'enabled'          => (bool)$request->getBodyParam('enabled', true),
            'notifyEmail'      => $request->getBodyParam('notifyEmail'),
            'notifyWebhookUrl' => $request->getBodyParam('notifyWebhookUrl'),
            'notifyOnSuccess'  => (bool)$request->getBodyParam('notifyOnSuccess', false),
        ];

        $steps = $request->getBodyParam('steps', []);

        if ($id) {
            $success = SynMon::getInstance()->getSuiteService()->updateSuite((int)$id, $data);
            $suiteId = (int)$id;
        } else {
            $suiteId = SynMon::getInstance()->getSuiteService()->createSuite($data);
            $success = $suiteId !== false;
        }

        if ($success) {
            SynMon::getInstance()->getSuiteService()->saveSteps((int)$suiteId, is_array($steps) ? $steps : []);
            Craft::$app->getSession()->setNotice('Suite gespeichert.');
            return $this->redirect('synmon/suites/' . $suiteId);
        }

        Craft::$app->getSession()->setError('Fehler beim Speichern.');
        return $this->redirect('synmon/suites');
    }

    /**
     * AJAX version of save – returns JSON (used by live test view).
     */
    public function actionSaveAjax(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $request = Craft::$app->getRequest();

        $id   = $request->getBodyParam('suiteId');
        $data = [
            'name'             => $request->getBodyParam('name'),
            'description'      => $request->getBodyParam('description'),
            'cronExpression'   => $request->getBodyParam('cronExpression', '*/5 * * * *'),
            'enabled'          => (bool)$request->getBodyParam('enabled', true),
            'notifyEmail'      => $request->getBodyParam('notifyEmail'),
            'notifyWebhookUrl' => $request->getBodyParam('notifyWebhookUrl'),
            'notifyOnSuccess'  => (bool)$request->getBodyParam('notifyOnSuccess', false),
        ];

        $steps = $request->getBodyParam('steps', []);

        try {
            if ($id) {
                $success = SynMon::getInstance()->getSuiteService()->updateSuite((int)$id, $data);
                $suiteId = (int)$id;
            } else {
                $suiteId = SynMon::getInstance()->getSuiteService()->createSuite($data);
                $success = $suiteId !== false;
            }

            if (!$success) {
                return $this->asJson(['success' => false, 'error' => 'Fehler beim Speichern.']);
            }

            SynMon::getInstance()->getSuiteService()->saveSteps((int)$suiteId, is_array($steps) ? $steps : []);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'suiteId' => $suiteId]);
    }

    public function actionDelete(): \yii\web\Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('suiteId');

        SynMon::getInstance()->getSuiteService()->deleteSuite($id);
        Craft::$app->getSession()->setNotice('Suite gelöscht.');
        return $this->redirect('synmon/suites');
    }

    public function actionRun(): \yii\web\Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('suiteId');

        Craft::$app->queue->push(new RunSuiteJob([
            'suiteId' => $id,
            'trigger' => 'manual',
        ]));

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson([
                'success'  => true,
                'message'  => 'Suite in Queue eingereiht.',
                'runsUrl'  => \craft\helpers\UrlHelper::cpUrl('synmon/runs'),
            ]);
        }

        Craft::$app->getSession()->setNotice('Suite in Queue eingereiht.');
        return $this->redirect('synmon/runs');
    }

    /**
     * Start a live run: pre-creates the RunRecord so the frontend can poll
     * immediately, then pushes a live-mode queue job.
     */
    public function actionRunLive(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id    = (int)Craft::$app->getRequest()->getRequiredBodyParam('suiteId');
        $suite = SynMon::getInstance()->getSuiteService()->getSuiteById($id);

        if (!$suite) {
            return $this->asJson(['success' => false, 'error' => 'Suite not found']);
        }

        // Pre-create run record so we have an ID for screenshot storage
        $runRecord          = new RunRecord();
        $runRecord->uid     = StringHelper::UUID();
        $runRecord->suiteId = $id;
        $runRecord->status  = 'running';
        $runRecord->trigger = 'manual';

        try {
            if (!$runRecord->save()) {
                $errors = $runRecord->getErrors();
                return $this->asJson(['success' => false, 'error' => 'Run konnte nicht erstellt werden: ' . json_encode($errors)]);
            }
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        $runId = $runRecord->id;

        Craft::$app->queue->push(new RunSuiteJob([
            'suiteId'  => $id,
            'trigger'  => 'manual',
            'liveMode' => true,
            'runId'    => $runId,
        ]));

        return $this->asJson([
            'success'       => true,
            'runId'         => $runId,
            'liveStatusUrl' => \craft\helpers\UrlHelper::cpUrl('synmon/runs/' . $runId . '/live-status'),
        ]);
    }

    public function actionClone(int $id): \yii\web\Response
    {
        $newId = SynMon::getInstance()->getSuiteService()->cloneSuite($id);

        if (!$newId) {
            Craft::$app->getSession()->setError('Klonen fehlgeschlagen.');
            return $this->redirect('synmon/suites');
        }

        Craft::$app->getSession()->setNotice('Suite geklont (deaktiviert).');
        return $this->redirect('synmon/suites/' . $newId);
    }

    public function actionExport(int $id): \yii\web\Response
    {
        $suite = SynMon::getInstance()->getSuiteService()->getSuiteById($id);
        if (!$suite) {
            throw new \yii\web\NotFoundHttpException("Suite #{$id} not found.");
        }

        $steps = SynMon::getInstance()->getSuiteService()->getStepsBySuiteId($id);

        $export = [
            'synmonExport' => '1.0',
            'suite' => [
                'name'             => $suite['name'],
                'description'      => $suite['description'],
                'cronExpression'   => $suite['cronExpression'],
                'notifyEmail'      => $suite['notifyEmail'],
                'notifyWebhookUrl' => $suite['notifyWebhookUrl'],
                'notifyOnSuccess'  => $suite['notifyOnSuccess'],
            ],
            'steps' => array_map(fn($s) => [
                'sortOrder'   => $s['sortOrder'],
                'type'        => $s['type'],
                'selector'    => $s['selector'],
                'value'       => $s['value'],
                'description' => $s['description'],
                'timeout'     => $s['timeout'],
            ], $steps),
        ];

        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $filename = 'synmon-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($suite['name'])) . '.json';

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->content = $json;

        return $response;
    }

    public function actionImport(): \yii\web\Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $file = $_FILES['importFile'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Craft::$app->getSession()->setError('Keine Datei hochgeladen.');
            return $this->redirect('synmon/suites');
        }

        $json    = file_get_contents($file['tmp_name']);
        $data    = json_decode($json, true);

        if (!$data || empty($data['synmonExport']) || empty($data['suite'])) {
            Craft::$app->getSession()->setError('Ungültige Export-Datei.');
            return $this->redirect('synmon/suites');
        }

        $suiteData = $data['suite'];
        $suiteData['enabled'] = false; // always import as disabled

        $suiteId = SynMon::getInstance()->getSuiteService()->createSuite($suiteData);

        if (!$suiteId) {
            Craft::$app->getSession()->setError('Import fehlgeschlagen.');
            return $this->redirect('synmon/suites');
        }

        $steps = $data['steps'] ?? [];
        SynMon::getInstance()->getSuiteService()->saveSteps($suiteId, $steps);

        Craft::$app->getSession()->setNotice('Suite importiert (deaktiviert).');
        return $this->redirect('synmon/suites/' . $suiteId);
    }

    public function actionToggle(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id    = (int)Craft::$app->getRequest()->getRequiredBodyParam('suiteId');
        $suite = SynMon::getInstance()->getSuiteService()->getSuiteById($id);

        if (!$suite) {
            return $this->asJson(['success' => false]);
        }

        $newEnabled = !$suite['enabled'];
        SynMon::getInstance()->getSuiteService()->updateSuite($id, ['enabled' => $newEnabled]);

        return $this->asJson(['success' => true, 'enabled' => $newEnabled]);
    }

    public function actionAddStep(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $type      = Craft::$app->getRequest()->getRequiredBodyParam('type');
        $index     = (int)Craft::$app->getRequest()->getBodyParam('index', 0);
        $stepTypes = SynMon::getInstance()->getSuiteService()->getStepTypes();

        $html = Craft::$app->getView()->renderTemplate('synmon/cp/suites/_step-row', [
            'step'      => ['type' => $type, 'selector' => '', 'value' => '', 'description' => '', 'timeout' => 30000],
            'index'     => $index,
            'stepTypes' => $stepTypes,
        ], \craft\web\View::TEMPLATE_MODE_CP);

        return $this->asJson(['success' => true, 'html' => $html]);
    }
}
