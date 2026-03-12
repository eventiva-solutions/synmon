<?php

namespace eventiva\synmon\controllers;

use Craft;
use craft\web\Controller;
use eventiva\synmon\SynMon;

class DashboardController extends Controller
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
        $stats     = SynMon::getInstance()->getResultService()->getDashboardStats();
        $settings  = SynMon::getInstance()->getResultService()->getSettings();
        $scheduler = SynMon::getInstance()->getSchedulerService();
        $suites    = SynMon::getInstance()->getSuiteService()->getSuites();

        $nextRuns = [];
        foreach ($suites as $suite) {
            if ($suite['enabled']) {
                $next = $scheduler->getNextRunTime($suite['cronExpression']);
                $nextRuns[$suite['id']] = $next ? $next->format('d.m.Y H:i') : null;
            }
        }

        return $this->renderTemplate('synmon/cp/dashboard/index', [
            'title'    => 'SynMon Dashboard',
            'stats'    => $stats,
            'settings' => $settings,
            'suites'   => $suites,
            'nextRuns' => $nextRuns,
        ]);
    }
}
