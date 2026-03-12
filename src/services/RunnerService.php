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
    public function runSuite(int $suiteId, string $trigger = 'manual', bool $liveMode = false, ?int $existingRunId = null): array
    {
        $suite = SynMon::getInstance()->getSuiteService()->getSuiteById($suiteId);
        if (!$suite) {
            return ['success' => false, 'error' => 'Suite not found'];
        }

        $steps = SynMon::getInstance()->getSuiteService()->getStepsBySuiteId($suiteId);

        // Use pre-created run record (live mode) or create new one
        if ($existingRunId) {
            $runId = $existingRunId;
        } else {
            $runRecord              = new RunRecord();
            $runRecord->uid         = StringHelper::UUID();
            $runRecord->suiteId     = $suiteId;
            $runRecord->status      = 'running';
            $runRecord->trigger     = $trigger;
            $runRecord->save();
            $runId = $runRecord->id;
        }

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
        $payload = $this->buildPayload($suite, $steps, $liveMode, $runId);

        if ($liveMode) {
            $result = $this->executeLiveRunner($payload, $runId, $steps);
        } else {
            $result = $this->executeRunner($payload);
            // Persist step logs
            $this->saveStepLogs($runId, $steps, $result['steps'] ?? []);
        }

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
        $nodeDir = $this->getNodeDir();

        if (!is_dir($nodeDir . '/node_modules/playwright')) {
            return false;
        }

        $browserDir = $this->getBrowserDir();
        $pattern    = $browserDir . '/chromium*/chrome-headless-shell-linux64/chrome-headless-shell';
        $matches    = glob($pattern);
        return !empty($matches);
    }

    /**
     * Ensures npm packages and Playwright browser are installed.
     * Returns null on success, error string on failure.
     */
    public function ensurePlaywright(): ?string
    {
        if ($this->checkPlaywrightAvailable()) {
            return null;
        }

        $nodeDir    = $this->getNodeDir();
        $browserDir = $this->getBrowserDir();
        $settings   = SynMon::getInstance()->getResultService()->getSettings();
        $nodeBinary = $settings['nodeBinary'] ?? 'node';
        $npmBinary  = dirname($nodeBinary) . '/npm';
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

        // Step 2: Install Chromium into storage directory (writable by www-data)
        Craft::info('SynMon: Installing Playwright Chromium browser...', __METHOD__);

        $playwrightBin = $nodeDir . '/node_modules/.bin/playwright';
        $env           = 'PLAYWRIGHT_BROWSERS_PATH=' . escapeshellarg($browserDir);
        $cmd           = 'cd ' . escapeshellarg($nodeDir) . ' && ' . $env . ' ' . escapeshellarg($playwrightBin) . ' install chromium 2>&1';
        $output        = [];
        $code          = 0;
        exec($cmd, $output, $code);

        if ($code !== 0) {
            return 'Playwright browser install failed: ' . implode("\n", array_slice($output, -5));
        }

        Craft::info('SynMon: Playwright ready.', __METHOD__);
        return null;
    }

    public function buildPayload(array $suite, array $steps, bool $liveMode = false, ?int $runId = null): array
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

        $payload = [
            'steps'         => $stepPayloads,
            'globalTimeout' => $settings['globalTimeout'] ?? 120,
        ];

        if ($liveMode && $runId) {
            $payload['liveMode']      = true;
            $payload['screenshotDir'] = $this->getScreenshotDir($runId);
        }

        return $payload;
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

        $cmd        = escapeshellcmd($nodeBinary) . ' ' . escapeshellarg($runnerPath);
        $browserDir = $this->getBrowserDir();

        $env = array_merge($_ENV, [
            'PLAYWRIGHT_BROWSERS_PATH' => $browserDir,
            'HOME'                     => $this->getNodeDir(),
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $this->getNodeDir(), $env);

        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Failed to start Node.js process'];
        }

        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout    = '';
        $stderr    = '';
        $startTime = time();

        while (true) {
            $read    = [$pipes[1], $pipes[2]];
            $write   = null;
            $except  = null;
            $changed = stream_select($read, $write, $except, 1);

            if ($changed === false) break;

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

            if (feof($pipes[1]) && feof($pipes[2])) break;

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

    /**
     * Executes runner in live mode: reads NDJSON line-by-line and saves
     * each step result to DB immediately as it completes.
     */
    public function executeLiveRunner(array $payload, int $runId, array $steps): array
    {
        $runnerPath = $this->getRunnerPath();

        if (!file_exists($runnerPath)) {
            return ['success' => false, 'error' => 'runner.js not found at: ' . $runnerPath];
        }

        $settings   = SynMon::getInstance()->getResultService()->getSettings();
        $nodeBinary = $settings['nodeBinary'] ?? 'node';
        $timeout    = ($settings['globalTimeout'] ?? 120) + 30; // extra time for screenshots

        $cmd        = escapeshellcmd($nodeBinary) . ' ' . escapeshellarg($runnerPath);
        $browserDir = $this->getBrowserDir();

        $env = array_merge($_ENV, [
            'PLAYWRIGHT_BROWSERS_PATH' => $browserDir,
            'HOME'                     => $this->getNodeDir(),
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $this->getNodeDir(), $env);

        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Failed to start Node.js process'];
        }

        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffer    = '';
        $stderr    = '';
        $summary   = null;
        $startTime = time();

        // Build step info map for quick lookup
        $stepMap = [];
        foreach ($steps as $s) {
            $stepMap[$s['sortOrder'] ?? 0] = $s;
        }

        while (true) {
            $read    = [$pipes[1], $pipes[2]];
            $write   = null;
            $except  = null;
            $changed = stream_select($read, $write, $except, 1);

            if ($changed === false) break;

            if ($changed > 0) {
                foreach ($read as $stream) {
                    if ($stream === $pipes[1]) {
                        $chunk = fread($pipes[1], 65536);
                        if ($chunk !== false) {
                            $buffer .= $chunk;
                            // Process complete lines
                            while (($pos = strpos($buffer, "\n")) !== false) {
                                $line   = substr($buffer, 0, $pos);
                                $buffer = substr($buffer, $pos + 1);
                                $this->processLiveLine($line, $runId, $stepMap);
                                $data = json_decode($line, true);
                                if (is_array($data) && ($data['type'] ?? '') === 'summary') {
                                    $summary = $data;
                                }
                            }
                        }
                    } elseif ($stream === $pipes[2]) {
                        $chunk = fread($pipes[2], 8192);
                        if ($chunk !== false) $stderr .= $chunk;
                    }
                }
            }

            if (feof($pipes[1]) && feof($pipes[2])) {
                // Process any remaining buffer
                if (!empty(trim($buffer))) {
                    $this->processLiveLine($buffer, $runId, $stepMap);
                }
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

        if ($summary === null) {
            return ['success' => false, 'error' => 'No summary received. STDERR: ' . substr($stderr, 0, 500)];
        }

        return $summary;
    }

    private function processLiveLine(string $line, int $runId, array $stepMap): void
    {
        $line = trim($line);
        if (empty($line)) return;

        $data = json_decode($line, true);
        if (!is_array($data)) return;

        // Network events: append to sidecar file
        if (($data['type'] ?? '') === 'network') {
            $networkFile = $this->getScreenshotDir($runId) . '/network.json';
            $dir = dirname($networkFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $existing = file_exists($networkFile)
                ? (json_decode(file_get_contents($networkFile), true) ?: [])
                : [];
            $existing[] = [
                'kind'         => $data['kind'] ?? 'request',
                'method'       => $data['method'] ?? null,
                'url'          => $data['url'] ?? null,
                'status'       => $data['status'] ?? null,
                'resourceType' => $data['resourceType'] ?? null,
                't'            => $data['t'] ?? null,
            ];
            file_put_contents($networkFile, json_encode($existing));
            return;
        }

        if (($data['type'] ?? '') !== 'step') return;

        $sortOrder    = $data['sortOrder'] ?? 0;
        $stepInfo     = $stepMap[$sortOrder] ?? null;
        $screenshotFile = $data['screenshotFile'] ?? null;
        $screenshotPath = null;

        if ($screenshotFile) {
            $screenshotPath = $this->getScreenshotDir($runId) . '/' . $screenshotFile;
        }

        // Check if log already exists for this runId + sortOrder (avoid duplicates)
        $exists = \Craft::$app->getDb()->createCommand(
            'SELECT id FROM {{%synmon_step_logs}} WHERE runId = :r AND sortOrder = :s LIMIT 1'
        )->bindValues([':r' => $runId, ':s' => $sortOrder])->queryScalar();

        if ($exists) return;

        $log                 = new StepLogRecord();
        $log->runId          = $runId;
        $log->stepId         = $stepInfo['id'] ?? null;
        $log->sortOrder      = $sortOrder;
        $log->type           = $data['type'] ?? ($stepInfo['type'] ?? 'unknown');  // 'step' type from NDJSON – use stepInfo
        $log->type           = $stepInfo['type'] ?? ($data['type'] ?? 'unknown');
        $log->selector       = $stepInfo['selector'] ?? null;
        $log->value          = $stepInfo['value'] ?? null;
        $log->status         = $data['status'] ?? 'skip';
        $log->durationMs     = $data['durationMs'] ?? null;
        $log->errorMessage   = $data['errorMessage'] ?? null;
        $log->consoleOutput  = $data['consoleOutput'] ?? null;
        $log->screenshotPath = $screenshotPath;
        $log->save();
    }

    private function getNodeDir(): string
    {
        return dirname(__DIR__) . '/node';
    }

    private function getBrowserDir(): string
    {
        return Craft::$app->getPath()->getStoragePath() . '/synmon-playwright-browsers';
    }

    private function getScreenshotDir(int $runId): string
    {
        return Craft::$app->getPath()->getStoragePath() . '/synmon-screenshots/' . $runId;
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
        $resultMap = [];
        foreach ($stepResults as $sr) {
            $resultMap[$sr['sortOrder'] ?? 0] = $sr;
        }

        foreach ($steps as $step) {
            $sr                  = $resultMap[$step['sortOrder'] ?? 0] ?? [];
            $log                 = new StepLogRecord();
            $log->runId          = $runId;
            $log->stepId         = $step['id'] ?? null;
            $log->sortOrder      = $step['sortOrder'] ?? 0;
            $log->type           = $step['type'];
            $log->selector       = $step['selector'] ?? null;
            $log->value          = $step['value'] ?? null;
            $log->status         = $sr['status'] ?? 'skip';
            $log->durationMs     = $sr['durationMs'] ?? null;
            $log->errorMessage   = $sr['errorMessage'] ?? null;
            $log->consoleOutput  = $sr['consoleOutput'] ?? null;
            $log->screenshotPath = null;
            $log->save();
        }
    }
}
