<?php

namespace eventiva\synmon\controllers;

use Craft;
use craft\web\Controller;
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
        $suites = SynMon::getInstance()->getSuiteService()->getSuites();
        return $this->renderTemplate('synmon/cp/suites/index', [
            'title'  => 'Test Suites',
            'suites' => $suites,
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

    public function actionToggle(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id      = (int)Craft::$app->getRequest()->getRequiredBodyParam('suiteId');
        $suite   = SynMon::getInstance()->getSuiteService()->getSuiteById($id);

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
