<?php

namespace eventiva\synmon\console\controllers;

use Craft;
use eventiva\synmon\jobs\RunSuiteJob;
use eventiva\synmon\SynMon;
use yii\console\Controller;
use yii\console\ExitCode;

class RunController extends Controller
{
    public $defaultAction = 'index';

    /**
     * Queue all due suites (used by cron: * * * * * php craft synmon/run)
     */
    public function actionIndex(): int
    {
        $settings = SynMon::getInstance()->getResultService()->getSettings();

        if (empty($settings['enabled'])) {
            echo "SynMon is disabled.\n";
            return ExitCode::OK;
        }

        $dueSuites  = SynMon::getInstance()->getSchedulerService()->getDueSuites();
        $allSuites  = SynMon::getInstance()->getSuiteService()->getSuites();

        if (empty($dueSuites)) {
            echo "No suites due.\n";
            if (!empty($allSuites)) {
                echo "Available suites:\n";
                foreach ($allSuites as $s) {
                    $status = $s['enabled'] ? 'enabled' : 'disabled';
                    echo "  #{$s['id']}  {$s['name']}  [{$status}]  cron: {$s['cronExpression']}\n";
                }
                echo "Run a specific suite now: php craft synmon/run/suite <id>\n";
            }
            return ExitCode::OK;
        }

        foreach ($dueSuites as $suite) {
            Craft::$app->queue->push(new RunSuiteJob([
                'suiteId' => $suite['id'],
                'trigger' => 'cron',
            ]));
            echo "Queued suite #{$suite['id']}: {$suite['name']}\n";
        }

        // Purge old runs
        $keepDays = $settings['runRetentionDays'] ?? 30;
        if ($keepDays > 0) {
            $deleted = SynMon::getInstance()->getResultService()->deleteOldRuns($keepDays);
            if ($deleted > 0) {
                echo "Purged {$deleted} old runs.\n";
            }
        }

        return ExitCode::OK;
    }

    /**
     * Immediately run a specific suite: php craft synmon/run/suite <id>
     */
    public function actionSuite(int $id): int
    {
        $suite = SynMon::getInstance()->getSuiteService()->getSuiteById($id);
        if (!$suite) {
            echo "Suite #{$id} not found.\n";
            return ExitCode::UNSPECIFIED_ERROR;
        }

        echo "Queuing suite #{$id}: {$suite['name']}...\n";

        Craft::$app->queue->push(new RunSuiteJob([
            'suiteId' => $id,
            'trigger' => 'manual',
        ]));

        echo "Job queued. Run 'php craft queue/run' to process.\n";
        return ExitCode::OK;
    }
}
