<?php

namespace eventiva\synmon\migrations;

use craft\db\Migration;

class m000000_000000_synmon_install extends Migration
{
    public function safeUp(): bool
    {
        // synmon_suites
        if (!$this->db->tableExists('{{%synmon_suites}}')) {
            $this->createTable('{{%synmon_suites}}', [
                'id'                => $this->primaryKey(),
                'uid'               => $this->uid()->notNull(),
                'name'              => $this->string(255)->notNull(),
                'description'       => $this->text()->null(),
                'cronExpression'    => $this->string(100)->notNull()->defaultValue('*/5 * * * *'),
                'enabled'           => $this->boolean()->notNull()->defaultValue(true),
                'notifyEmail'       => $this->string(255)->null(),
                'notifyWebhookUrl'  => $this->string(1000)->null(),
                'notifyOnSuccess'   => $this->boolean()->notNull()->defaultValue(false),
                'lastRunAt'         => $this->dateTime()->null(),
                'lastRunStatus'     => $this->enum('lastRunStatus', ['pass', 'fail', 'error'])->null(),
                'dateCreated'       => $this->dateTime()->notNull(),
                'dateUpdated'       => $this->dateTime()->notNull(),
            ]);
            $this->createIndex(null, '{{%synmon_suites}}', ['enabled']);
        }

        // synmon_steps
        if (!$this->db->tableExists('{{%synmon_steps}}')) {
            $this->createTable('{{%synmon_steps}}', [
                'id'          => $this->primaryKey(),
                'uid'         => $this->uid()->notNull(),
                'suiteId'     => $this->integer()->notNull(),
                'sortOrder'   => $this->integer()->notNull()->defaultValue(0),
                'type'        => $this->string(50)->notNull(),
                'selector'    => $this->string(500)->null(),
                'value'       => $this->string(1000)->null(),
                'description' => $this->string(500)->null(),
                'timeout'     => $this->integer()->notNull()->defaultValue(30000),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);
            $this->createIndex(null, '{{%synmon_steps}}', ['suiteId']);
            $this->createIndex(null, '{{%synmon_steps}}', ['suiteId', 'sortOrder']);
            $this->addForeignKey(null, '{{%synmon_steps}}', 'suiteId', '{{%synmon_suites}}', 'id', 'CASCADE', 'CASCADE');
        }

        // synmon_runs
        if (!$this->db->tableExists('{{%synmon_runs}}')) {
            $this->createTable('{{%synmon_runs}}', [
                'id'                => $this->primaryKey(),
                'uid'               => $this->uid()->notNull(),
                'suiteId'           => $this->integer()->notNull(),
                'status'            => $this->enum('status', ['running', 'pass', 'fail', 'error'])->notNull()->defaultValue('running'),
                'trigger'           => $this->enum('trigger', ['cron', 'manual'])->notNull()->defaultValue('manual'),
                'durationMs'        => $this->integer()->null(),
                'failedStep'        => $this->integer()->null(),
                'errorMessage'      => $this->text()->null(),
                'nodeVersion'       => $this->string(50)->null(),
                'playwrightVersion' => $this->string(50)->null(),
                'dateCreated'       => $this->dateTime()->notNull(),
                'dateUpdated'       => $this->dateTime()->notNull(),
            ]);
            $this->createIndex(null, '{{%synmon_runs}}', ['suiteId']);
            $this->createIndex(null, '{{%synmon_runs}}', ['status']);
            $this->createIndex(null, '{{%synmon_runs}}', ['dateCreated']);
            $this->addForeignKey(null, '{{%synmon_runs}}', 'suiteId', '{{%synmon_suites}}', 'id', 'CASCADE', 'CASCADE');
        }

        // synmon_step_logs
        if (!$this->db->tableExists('{{%synmon_step_logs}}')) {
            $this->createTable('{{%synmon_step_logs}}', [
                'id'            => $this->primaryKey(),
                'runId'         => $this->integer()->notNull(),
                'stepId'        => $this->integer()->null(),
                'sortOrder'     => $this->integer()->notNull()->defaultValue(0),
                'type'          => $this->string(50)->notNull(),
                'selector'      => $this->string(500)->null(),
                'value'         => $this->string(1000)->null(),
                'status'        => $this->enum('status', ['pass', 'fail', 'skip'])->notNull()->defaultValue('pass'),
                'durationMs'    => $this->integer()->null(),
                'errorMessage'  => $this->text()->null(),
                'consoleOutput' => $this->text()->null(),
                'dateCreated'   => $this->dateTime()->notNull(),
                'dateUpdated'   => $this->dateTime()->notNull(),
            ]);
            $this->createIndex(null, '{{%synmon_step_logs}}', ['runId']);
            $this->addForeignKey(null, '{{%synmon_step_logs}}', 'runId', '{{%synmon_runs}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%synmon_step_logs}}', 'stepId', '{{%synmon_steps}}', 'id', 'SET NULL', 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%synmon_step_logs}}');
        $this->dropTableIfExists('{{%synmon_runs}}');
        $this->dropTableIfExists('{{%synmon_steps}}');
        $this->dropTableIfExists('{{%synmon_suites}}');
        return true;
    }
}
