<?php

namespace eventiva\synmon\jobs;

use Craft;
use craft\queue\BaseJob;
use eventiva\synmon\SynMon;

class RunSuiteJob extends BaseJob
{
    public int    $suiteId;
    public string $trigger  = 'cron';
    public bool   $liveMode = false;
    public ?int   $runId    = null;

    public function execute($queue): void
    {
        $suite = SynMon::getInstance()->getSuiteService()->getSuiteById($this->suiteId);

        if (!$suite) {
            Craft::warning("RunSuiteJob: Suite #{$this->suiteId} not found – skipping.", __METHOD__);
            return;
        }

        try {
            $result = SynMon::getInstance()->getRunnerService()->runSuite(
                $this->suiteId,
                $this->trigger,
                $this->liveMode,
                $this->runId
            );
            Craft::info(
                "RunSuiteJob: Suite #{$this->suiteId} ({$suite['name']}) – status: " . ($result['status'] ?? 'unknown'),
                __METHOD__
            );
        } catch (\Throwable $e) {
            Craft::error("RunSuiteJob: Suite #{$this->suiteId} failed: " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'SynMon: Run Suite #' . $this->suiteId . ($this->liveMode ? ' (Live)' : '');
    }
}
