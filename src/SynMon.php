<?php

namespace eventiva\synmon;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;
use eventiva\synmon\migrations\m000000_000000_synmon_install;
use eventiva\synmon\models\Settings;
use eventiva\synmon\services\NotificationService;
use eventiva\synmon\services\ResultService;
use eventiva\synmon\services\RunnerService;
use eventiva\synmon\services\SchedulerService;
use eventiva\synmon\services\SuiteService;
use yii\base\Event;

/**
 * SynMon plugin
 *
 * @method static SynMon getInstance()
 * @property-read SuiteService $suites
 * @property-read SchedulerService $scheduler
 * @property-read RunnerService $runner
 * @property-read ResultService $results
 * @property-read NotificationService $notifications
 */
class SynMon extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    public function init(): void
    {
        parent::init();

        // Register console controller namespace so `php craft synmon/run` works
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'eventiva\\synmon\\console\\controllers';
        }

        $this->runMigrationsIfNeeded();

        $this->setComponents([
            'suites'        => SuiteService::class,
            'scheduler'     => SchedulerService::class,
            'runner'        => RunnerService::class,
            'results'       => ResultService::class,
            'notifications' => NotificationService::class,
        ]);

        $this->registerTemplateRoots();
        $this->registerUrlRules();

        Craft::info('SynMon plugin loaded.', __METHOD__);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $item['label'] = Craft::t('synmon', 'SynMon');
        $item['url']   = 'synmon';
        $item['icon']  = __DIR__ . '/icon.svg';

        $item['subnav'] = [
            'dashboard' => ['label' => Craft::t('synmon', 'Dashboard'),    'url' => 'synmon'],
            'suites'    => ['label' => Craft::t('synmon', 'Test Suites'),  'url' => 'synmon/suites'],
            'runs'      => ['label' => Craft::t('synmon', 'Run History'),  'url' => 'synmon/runs'],
            'settings'  => ['label' => Craft::t('synmon', 'Einstellungen'),'url' => 'synmon/settings'],
        ];

        return $item;
    }

    public function getSuiteService(): SuiteService
    {
        return $this->get('suites');
    }

    public function getSchedulerService(): SchedulerService
    {
        return $this->get('scheduler');
    }

    public function getRunnerService(): RunnerService
    {
        return $this->get('runner');
    }

    public function getResultService(): ResultService
    {
        return $this->get('results');
    }

    public function getNotificationService(): NotificationService
    {
        return $this->get('notifications');
    }

    private function registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['synmon'] = $this->getBasePath() . '/templates';
            }
        );
    }

    private function registerUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'synmon'                          => 'synmon/dashboard/index',
                    'synmon/suites'                   => 'synmon/suites/index',
                    'synmon/suites/new'               => 'synmon/suites/new',
                    'synmon/suites/<id:\d+>'          => 'synmon/suites/edit',
                    'synmon/suites/<id:\d+>/live'     => 'synmon/suites/live',
                    'synmon/suites/save'              => 'synmon/suites/save',
                    'synmon/suites/save-ajax'         => 'synmon/suites/save-ajax',
                    'synmon/suites/delete'            => 'synmon/suites/delete',
                    'synmon/suites/run'               => 'synmon/suites/run',
                    'synmon/suites/run-live'          => 'synmon/suites/run-live',
                    'synmon/suites/add-step'          => 'synmon/suites/add-step',
                    'synmon/suites/toggle'            => 'synmon/suites/toggle',
                    'synmon/suites/<id:\d+>/clone'    => 'synmon/suites/clone',
                    'synmon/suites/<id:\d+>/export'   => 'synmon/suites/export',
                    'synmon/suites/import'            => 'synmon/suites/import',
                    'synmon/runs'                     => 'synmon/runs/index',
                    'synmon/runs/<id:\d+>'            => 'synmon/runs/detail',
                    'synmon/runs/delete'              => 'synmon/runs/delete',
                    'synmon/runs/purge'               => 'synmon/runs/purge',
                    'synmon/runs/cancel'              => 'synmon/runs/cancel',
                    'synmon/runs/<id:\d+>/live-status'=> 'synmon/runs/live-status',
                    'synmon/runs/screenshot'          => 'synmon/runs/screenshot',
                    'synmon/settings'                 => 'synmon/settings/index',
                    'synmon/settings/save'            => 'synmon/settings/save',
                ]);
            }
        );
    }

    private function runMigrationsIfNeeded(): void
    {
        $db = Craft::$app->getDb();

        if (!$db->tableExists('{{%synmon_suites}}')) {
            $this->runMigration(new m000000_000000_synmon_install());
            Craft::info('SynMon: DB tables created.', __METHOD__);
        }

        // Add screenshotPath column if missing (added in v1.1.0)
        if ($db->tableExists('{{%synmon_step_logs}}')) {
            try {
                $db->createCommand()->addColumn('{{%synmon_step_logs}}', 'screenshotPath', 'TEXT NULL')->execute();
                Craft::info('SynMon: Added screenshotPath column.', __METHOD__);
            } catch (\Throwable $e) {
                // Column already exists – expected on subsequent loads
            }
        }
    }

    private function runMigration(object $migration): void
    {
        ob_start();
        $migration->up();
        ob_end_clean();
    }
}
