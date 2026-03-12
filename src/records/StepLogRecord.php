<?php

namespace eventiva\synmon\records;

use craft\db\ActiveRecord;

/**
 * @property int    $id
 * @property int    $runId
 * @property int    $stepId
 * @property int    $sortOrder
 * @property string $type
 * @property string $selector
 * @property string $value
 * @property string $status
 * @property int    $durationMs
 * @property string $errorMessage
 * @property string $consoleOutput
 * @property string $screenshotPath
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class StepLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%synmon_step_logs}}';
    }
}
