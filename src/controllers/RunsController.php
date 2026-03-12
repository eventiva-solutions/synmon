<?php

namespace eventiva\synmon\controllers;

use Craft;
use craft\web\Controller;
use eventiva\synmon\SynMon;

class RunsController extends Controller
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
        $request = Craft::$app->getRequest();
        $page    = max(1, (int)$request->getQueryParam('page', 1));
        $suiteId = $request->getQueryParam('suiteId') ? (int)$request->getQueryParam('suiteId') : null;
        $status  = $request->getQueryParam('status');
        $suites  = SynMon::getInstance()->getSuiteService()->getSuites();

        $data = SynMon::getInstance()->getResultService()->getRuns($page, 20, $suiteId, $status);

        return $this->renderTemplate('synmon/cp/runs/index', [
            'title'      => 'Run History',
            'runs'       => $data['runs'],
            'total'      => $data['total'],
            'page'       => $data['page'],
            'perPage'    => $data['perPage'],
            'totalPages' => $data['totalPages'],
            'suites'     => $suites,
            'suiteId'    => $suiteId,
            'status'     => $status,
        ]);
    }

    public function actionDetail(int $id): \yii\web\Response
    {
        $run = SynMon::getInstance()->getResultService()->getRunById($id);
        if (!$run) {
            throw new \yii\web\NotFoundHttpException("Run #{$id} not found.");
        }

        // Attach screenshot URLs to step logs if screenshots exist
        $screenshotBase    = Craft::$app->getPath()->getStoragePath() . '/synmon-screenshots/' . $id;
        $screenshotBaseUrl = \craft\helpers\UrlHelper::cpUrl('synmon/runs/screenshot');
        foreach ($run['stepLogs'] as &$log) {
            $file = $screenshotBase . '/step-' . $log['sortOrder'] . '.jpg';
            $log['screenshotUrl'] = file_exists($file)
                ? $screenshotBaseUrl . '?runId=' . $id . '&sortOrder=' . $log['sortOrder']
                : null;
        }
        unset($log);

        return $this->renderTemplate('synmon/cp/runs/_detail', [
            'title' => 'Run #' . $id . ' – ' . ($run['suiteName'] ?? ''),
            'run'   => $run,
        ]);
    }

    public function actionDelete(): \yii\web\Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('runId');

        $this->deleteRunScreenshots($id);
        Craft::$app->getDb()->createCommand()->delete('{{%synmon_runs}}', ['id' => $id])->execute();
        Craft::$app->getSession()->setNotice('Run gelöscht.');
        return $this->redirect('synmon/runs');
    }

    private function deleteRunScreenshots(int $runId): void
    {
        $dir = Craft::$app->getPath()->getStoragePath() . '/synmon-screenshots/' . $runId;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.jpg') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    public function actionPurge(): \yii\web\Response
    {
        $this->requirePostRequest();
        $days   = (int)Craft::$app->getRequest()->getBodyParam('days', 30);
        $cutoff = (new \DateTime())->modify("-{$days} days")->format('Y-m-d H:i:s');

        // Delete screenshots for old runs before deleting the records
        $oldIds = Craft::$app->getDb()->createCommand(
            'SELECT id FROM {{%synmon_runs}} WHERE dateCreated < :cutoff'
        )->bindValue(':cutoff', $cutoff)->queryColumn();

        foreach ($oldIds as $oldId) {
            $this->deleteRunScreenshots((int)$oldId);
        }

        $deleted = SynMon::getInstance()->getResultService()->deleteOldRuns($days);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'deleted' => $deleted]);
        }

        Craft::$app->getSession()->setNotice("{$deleted} Runs gelöscht.");
        return $this->redirect('synmon/runs');
    }

    /**
     * Cancels a running live test by marking it as cancelled in the DB.
     * The queue job continues in the background but the frontend stops polling.
     */
    public function actionCancel(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id  = (int)Craft::$app->getRequest()->getRequiredBodyParam('runId');
        $run = Craft::$app->getDb()->createCommand(
            'SELECT id, suiteId, status FROM {{%synmon_runs}} WHERE id = :id'
        )->bindValue(':id', $id)->queryOne();

        if (!$run || $run['status'] !== 'running') {
            return $this->asJson(['success' => false, 'error' => 'Run not found or not running']);
        }

        Craft::$app->getDb()->createCommand()->update('{{%synmon_runs}}', [
            'status'       => 'cancelled',
            'errorMessage' => 'Manuell abgebrochen.',
            'dateUpdated'  => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['id' => $id])->execute();

        SynMon::getInstance()->getSuiteService()->updateLastRunStatus((int)$run['suiteId'], 'cancelled');

        return $this->asJson(['success' => true]);
    }

    /**
     * Polling endpoint for live test view.
     * Returns current run status + all step logs completed so far.
     */
    public function actionLiveStatus(int $id): \yii\web\Response
    {
        $this->requireAcceptsJson();

        $run = Craft::$app->getDb()->createCommand(
            'SELECT r.*, s.name AS suiteName
             FROM {{%synmon_runs}} r
             LEFT JOIN {{%synmon_suites}} s ON s.id = r.suiteId
             WHERE r.id = :id'
        )->bindValue(':id', $id)->queryOne();

        if (!$run) {
            return $this->asJson(['success' => false, 'error' => 'Run not found']);
        }

        $stepLogs = Craft::$app->getDb()->createCommand(
            'SELECT * FROM {{%synmon_step_logs}} WHERE runId = :id ORDER BY sortOrder ASC'
        )->bindValue(':id', $id)->queryAll();

        $screenshotBaseUrl = \craft\helpers\UrlHelper::cpUrl('synmon/runs/screenshot');
        $screenshotBase    = Craft::$app->getPath()->getStoragePath() . '/synmon-screenshots/' . $id;
        foreach ($stepLogs as &$log) {
            $file = $screenshotBase . '/step-' . $log['sortOrder'] . '.jpg';
            $log['screenshotUrl'] = file_exists($file)
                ? $screenshotBaseUrl . '?runId=' . $id . '&sortOrder=' . $log['sortOrder']
                : null;
        }
        unset($log);

        // Network logs written by RunnerService to a sidecar file
        $networkFile = Craft::$app->getPath()->getStoragePath()
            . '/synmon-screenshots/' . $id . '/network.json';
        $networkLogs = file_exists($networkFile)
            ? (json_decode(file_get_contents($networkFile), true) ?: [])
            : [];

        return $this->asJson([
            'success'     => true,
            'status'      => $run['status'],
            'stepLogs'    => $stepLogs,
            'networkLogs' => $networkLogs,
            'error'       => $run['errorMessage'] ?? null,
        ]);
    }

    /**
     * Serves a screenshot for a specific run + step sortOrder.
     */
    public function actionScreenshot(): \yii\web\Response
    {
        $runId     = (int)Craft::$app->getRequest()->getRequiredQueryParam('runId');
        $sortOrder = (int)Craft::$app->getRequest()->getRequiredQueryParam('sortOrder');

        $filePath = Craft::$app->getPath()->getStoragePath()
            . '/synmon-screenshots/' . $runId . '/step-' . $sortOrder . '.jpg';

        if (!file_exists($filePath)) {
            throw new \yii\web\NotFoundHttpException('Screenshot not found.');
        }

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('Cache-Control', 'public, max-age=3600');
        $response->content = file_get_contents($filePath);

        return $response;
    }
}
