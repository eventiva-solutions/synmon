<?php

namespace eventiva\synmon\services;

use Craft;
use craft\helpers\StringHelper;
use eventiva\synmon\records\RunRecord;
use eventiva\synmon\records\StepLogRecord;
use eventiva\synmon\SynMon;
use yii\base\Component;

class RunnerService extends Component
{
    public function runSuite(int $suiteId, string $trigger = 'manual'): array
    {
        $suite = SynMon::getInstance()->getSuiteService()->getSuiteById($suiteId);
        if (!$suite) {
            return ['success' => false, 'error' => 'Suite not found'];
        }

        $steps = SynMon::getInstance()->getSuiteService()->getStepsBySuiteId($suiteId);

        // Create run record with status=running
        $runRecord              = new RunRecord();
        $runRecord->uid         = StringHelper::UUID();
        $runRecord->suiteId     = $suiteId;
        $runRecord->status      = 'running';
        $runRecord->trigger     = $trigger;
        $runRecord->save();

        $runId = $runRecord->id;

        // Check Node.js availability
        if (!$this->checkNodeAvailable()) {
            $this->failRun($runId, $suiteId, 'error', null, 'Node.js not found. Please check nodeBinary setting.');
            return ['success' => false, 'error' => 'Node.js not available'];
        }

        // Auto-setup Playwright if needed (npm install + browser download)
        $setupError = $this->ensurePlaywright();
        if ($setupError !== null) {
            $this->failRun($runId, $suiteId, 'error', null, $setupError);
            return ['success' => false, 'error' => $setupError];
        }

        // Build payload and execute
        $payload = $this->buildPayload($suite, $steps);
        $result  = $this->executeRunner($payload);

        // Persist step logs
        $this->saveStepLogs($runId, $steps, $result['steps'] ?? []);

        // Determine final status
        $status = $result['success'] ? 'pass' : 'fail';
        if (!empty($result['error']) && empty($result['steps'])) {
            $status = 'error';
        }

        // Update run record
        Craft::$app->getDb()->createCommand()->update('{{%synmon_runs}}', [
            'status'            => $status,
            'durationMs'        => $result['durationMs'] ?? null,
            'failedStep'        => $result['failedStep'] ?? null,
            'errorMessage'      => $result['error'] ?? null,
            'nodeVersion'       => $result['nodeVersion'] ?? null,
            'playwrightVersion' => $result['playwrightVersion'] ?? null,
            'dateUpdated'       => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['id' => $runId])->execute();

        // Update suite last run
        SynMon::getInstance()->getSuiteService()->updateLastRunStatus($suiteId, $status);

        // Send notifications if failed
        if ($status !== 'pass' || $suite['notifyOnSuccess']) {
            $failedStep = null;
            if (!empty($result['steps'])) {
                foreach ($result['steps'] as $stepResult) {
                    if (($stepResult['status'] ?? '') === 'fail') {
                        // find matching step
                        foreach ($steps as $s) {
                            if (($s['sortOrder'] ?? 0) == ($stepResult['sortOrder'] ?? -1)) {
                                $failedStep = $s;
                                break;
                            }
                        }
                        break;
                    }
                }
            }
            SynMon::getInstance()->getNotificationService()->notifyRun($runId, $suite, $failedStep, $status);
        }

        return ['success' => $result['success'], 'runId' => $runId, 'status' => $status];
    }

    public function checkNodeAvailable(): bool
    {
        $settings   = SynMon::getInstance()->getResultService()->getSettings();
        $nodeBinary = $settings['nodeBinary'] ?? 'node';

        $output = [];
        $code   = 0;
        exec(escapeshellcmd($nodeBinary) . ' --version 2>&1', $output, $code);
        return $code === 0;
    }

    public function checkPlaywrightAvailable(): bool
    {
        $nodeDir    = $this->getNodeDir();
        $browserDir = $nodeDir . '/.playwright-browsers';

        if (!is_dir($nodeDir . '/node_modules/playwright')) {
            return false;
        }

        // Check that at least one chrome-headless-shell exists in our browser dir
        $browserDir = $this->getBrowserDir();
        $pattern    = $browserDir . '/chromium*/chrome-headless-shell-linux64/chrome-headless-shell';
        $matches    = glob($pattern);
        return !empty($matches);
    }

    /**
     * Ensures npm packages and Playwright browser are installed.
     * Browsers are stored inside the plugin directory (user-independent).
     * Returns null on success, error string on failure.
     */
    public function ensurePlaywright(): ?string
    {
        if ($this->checkPlaywrightAvailable()) {
            return null;
        }

        $nodeDir      = $this->getNodeDir();
        $browserDir   = $this->getBrowserDir();
        $settings     = SynMon::getInstance()->getResultService()->getSettings();
        $nodeBinary   = $settings['nodeBinary'] ?? 'node';
        $npmBinary    = dirname($nodeBinary) . '/npm';
        if (!file_exists($npmBinary)) {
            $npmBinary = 'npm';
        }

        Craft::info('SynMon: Installing npm packages...', __METHOD__);

        // Step 1: npm install
        if (!is_dir($nodeDir . '/node_modules/playwright')) {
            $cmd    = 'cd ' . escapeshellarg($nodeDir) . ' && ' . escapeshellcmd($npmBinary) . ' install --prefix . 2>&1';
            $output = [];
            $code   = 0;
            exec($cmd, $output, $code);

            if ($code !== 0) {
                return 'npm install failed: ' . implode("\n", array_slice($output, -5));
            }
        }

        // Step 2: Install Chromium into plugin directory (not user home)
        Craft::info('SynMon: Installing Playwright Chromium browser...', __METHOD__);

        $playwrightBin = $nodeDir . '/node_modules/.bin/playwright';
        $env           = 'PLAYWRIGHT_BROWSERS_PATH=' . escapeshellarg($browserDir);

        $cmd    = 'cd ' . escapeshellarg($nodeDir) . ' && ' . $env . ' ' . escapeshellarg($playwrightBin) . ' install chromium 2>&1';
        $output = [];
        $code   = 0;
        exec($cmd, $output, $code);

        if ($code !== 0) {
            return 'Playwright browser install failed: ' . implode("\n", array_slice($output, -5));
        }

        Craft::info('SynMon: Playwright ready.', __METHOD__);
        return null;
    }

    public function buildPayload(array $suite, array $steps): array
    {
        $settings = SynMon::getInstance()->getResultService()->getSettings();

        $stepPayloads = [];
        foreach ($steps as $step) {
            $stepPayloads[] = [
                'sortOrder' => $step['sortOrder'],
                'type'      => $step['type'],
                'selector'  => $step['selector'] ?? null,
                'value'     => $step['value'] ?? null,
                'timeout'   => $step['timeout'] ?? ($settings['defaultTimeout'] ?? 30000),
            ];
        }

        return [
            'steps'         => $stepPayloads,
            'globalTimeout' => $settings['globalTimeout'] ?? 120,
        ];
    }

    public function executeRunner(array $payload): array
    {
        $runnerPath = $this->getRunnerPath();

        if (!file_exists($runnerPath)) {
            return ['success' => false, 'error' => 'runner.js not found at: ' . $runnerPath];
        }

        $settings   = SynMon::getInstance()->getResultService()->getSettings();
        $nodeBinary = $settings['nodeBinary'] ?? 'node';
        $timeout    = ($settings['globalTimeout'] ?? 120) + 10;

        $cmd = escapeshellcmd($nodeBinary) . ' ' . escapeshellarg($runnerPath);

        $browserDir = $this->getBrowserDir();

        $env = array_merge($_ENV, [
            'PLAYWRIGHT_BROWSERS_PATH' => $browserDir,
            'HOME'                     => $this->getNodeDir(),
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $this->getNodeDir(), $env);

        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Failed to start Node.js process'];
        }

        // Write payload to stdin
        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);

        // Read stdout with timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout    = '';
        $stderr    = '';
        $startTime = time();

        while (true) {
            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;

            $changed = stream_select($read, $write, $except, 1);

            if ($changed === false) {
                break;
            }

            if ($changed > 0) {
                foreach ($read as $stream) {
                    if ($stream === $pipes[1]) {
                        $chunk = fread($pipes[1], 8192);
                        if ($chunk !== false) $stdout .= $chunk;
                    } elseif ($stream === $pipes[2]) {
                        $chunk = fread($pipes[2], 8192);
                        if ($chunk !== false) $stderr .= $chunk;
                    }
                }
            }

            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }

            if ((time() - $startTime) >= $timeout) {
                proc_terminate($process);
                return ['success' => false, 'error' => 'Timeout: Runner exceeded ' . $timeout . 's'];
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (empty($stdout)) {
            return ['success' => false, 'error' => 'No output from runner. STDERR: ' . substr($stderr, 0, 500)];
        }

        $result = json_decode(trim($stdout), true);
        if (!is_array($result)) {
            return ['success' => false, 'error' => 'Invalid JSON from runner: ' . substr($stdout, 0, 500)];
        }

        return $result;
    }

    private function getNodeDir(): string
    {
        return dirname(__DIR__) . '/node';
    }

    private function getBrowserDir(): string
    {
        return Craft::$app->getPath()->getStoragePath() . '/synmon-playwright-browsers';
    }

    private function getRunnerPath(): string
    {
        return $this->getNodeDir() . '/runner.js';
    }

    private function failRun(int $runId, int $suiteId, string $status, ?int $failedStep, string $errorMessage): void
    {
        Craft::$app->getDb()->createCommand()->update('{{%synmon_runs}}', [
            'status'       => $status,
            'failedStep'   => $failedStep,
            'errorMessage' => $errorMessage,
            'dateUpdated'  => (new \DateTime())->format('Y-m-d H:i:s'),
        ], ['id' => $runId])->execute();

        SynMon::getInstance()->getSuiteService()->updateLastRunStatus($suiteId, $status);
    }

    private function saveStepLogs(int $runId, array $steps, array $stepResults): void
    {
        // Build a map sortOrder => result
        $resultMap = [];
        foreach ($stepResults as $sr) {
            $resultMap[$sr['sortOrder'] ?? 0] = $sr;
        }

        foreach ($steps as $step) {
            $sr     = $resultMap[$step['sortOrder'] ?? 0] ?? [];
            $log    = new StepLogRecord();
            $log->runId         = $runId;
            $log->stepId        = $step['id'] ?? null;
            $log->sortOrder     = $step['sortOrder'] ?? 0;
            $log->type          = $step['type'];
            $log->selector      = $step['selector'] ?? null;
            $log->value         = $step['value'] ?? null;
            $log->status        = $sr['status'] ?? 'skip';
            $log->durationMs    = $sr['durationMs'] ?? null;
            $log->errorMessage  = $sr['errorMessage'] ?? null;
            $log->consoleOutput = $sr['consoleOutput'] ?? null;
            $log->save();
        }
    }
}
