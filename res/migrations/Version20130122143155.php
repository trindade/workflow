<?php

namespace Scrutinizer\Workflow\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration,
    Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated Migration: Please modify to your need!
 */
class Version20130122143155 extends AbstractMigration
{
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql", "Migration can only be executed safely on 'mysql'.");
        
        $this->addSql("CREATE TABLE workflow_tasks (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, createdAt DATETIME NOT NULL, finishedAt DATETIME DEFAULT NULL, workflowExecution_id BIGINT UNSIGNED DEFAULT NULL, type VARCHAR(30) NOT NULL, control LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)', input LONGTEXT DEFAULT NULL, result LONGTEXT DEFAULT NULL, state VARCHAR(30) DEFAULT NULL, failureReason VARCHAR(255) DEFAULT NULL, failureException LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)', activityType_id INT UNSIGNED DEFAULT NULL, INDEX IDX_E11613A347B0D7BF (workflowExecution_id), INDEX IDX_E11613A3A1B4B28C (activityType_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE workflow_events (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, attributes LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)', createdAt DATETIME NOT NULL, workflowExecution_id BIGINT UNSIGNED DEFAULT NULL, INDEX IDX_7282ED8947B0D7BF (workflowExecution_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE workflow_executions (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, workflow_id INT UNSIGNED DEFAULT NULL, maxRuntime INT UNSIGNED NOT NULL, input LONGTEXT NOT NULL, state VARCHAR(15) NOT NULL, createdAt DATETIME NOT NULL, finishedAt DATETIME DEFAULT NULL, failureReason VARCHAR(255) DEFAULT NULL, failureDetails LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)', cancelDetails LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)', pendingDecisionTask TINYINT(1) NOT NULL, parentWorkflowExecutionTask_id BIGINT UNSIGNED DEFAULT NULL, INDEX IDX_402F685B2C7C2CBA (workflow_id), UNIQUE INDEX UNIQ_402F685B65CFB74D (parentWorkflowExecutionTask_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE workflow_execution_tags (execution_id BIGINT UNSIGNED NOT NULL, tag_id INT UNSIGNED NOT NULL, INDEX IDX_55853DE557125544 (execution_id), INDEX IDX_55853DE5BAD26311 (tag_id), PRIMARY KEY(execution_id, tag_id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE workflow_tags (id INT UNSIGNED AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, UNIQUE INDEX UNIQ_2A23841D5E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE workflows (id INT UNSIGNED AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, deciderQueueName VARCHAR(30) NOT NULL, UNIQUE INDEX UNIQ_EFBFBFC25E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE workflow_activity_types (id INT UNSIGNED AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, queueName VARCHAR(50) NOT NULL, UNIQUE INDEX UNIQ_9B7278BE5E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB");
        $this->addSql("ALTER TABLE workflow_tasks ADD CONSTRAINT FK_E11613A347B0D7BF FOREIGN KEY (workflowExecution_id) REFERENCES workflow_executions (id)");
        $this->addSql("ALTER TABLE workflow_tasks ADD CONSTRAINT FK_E11613A3A1B4B28C FOREIGN KEY (activityType_id) REFERENCES workflow_activity_types (id)");
        $this->addSql("ALTER TABLE workflow_events ADD CONSTRAINT FK_7282ED8947B0D7BF FOREIGN KEY (workflowExecution_id) REFERENCES workflow_executions (id)");
        $this->addSql("ALTER TABLE workflow_executions ADD CONSTRAINT FK_402F685B2C7C2CBA FOREIGN KEY (workflow_id) REFERENCES workflows (id)");
        $this->addSql("ALTER TABLE workflow_executions ADD CONSTRAINT FK_402F685B65CFB74D FOREIGN KEY (parentWorkflowExecutionTask_id) REFERENCES workflow_tasks (id)");
        $this->addSql("ALTER TABLE workflow_execution_tags ADD CONSTRAINT FK_55853DE557125544 FOREIGN KEY (execution_id) REFERENCES workflow_executions (id)");
        $this->addSql("ALTER TABLE workflow_execution_tags ADD CONSTRAINT FK_55853DE5BAD26311 FOREIGN KEY (tag_id) REFERENCES workflow_tags (id)");
    }

    public function down(Schema $schema)
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql", "Migration can only be executed safely on 'mysql'.");
        
        $this->addSql("ALTER TABLE workflow_executions DROP FOREIGN KEY FK_402F685B65CFB74D");
        $this->addSql("ALTER TABLE workflow_tasks DROP FOREIGN KEY FK_E11613A347B0D7BF");
        $this->addSql("ALTER TABLE workflow_events DROP FOREIGN KEY FK_7282ED8947B0D7BF");
        $this->addSql("ALTER TABLE workflow_execution_tags DROP FOREIGN KEY FK_55853DE557125544");
        $this->addSql("ALTER TABLE workflow_execution_tags DROP FOREIGN KEY FK_55853DE5BAD26311");
        $this->addSql("ALTER TABLE workflow_executions DROP FOREIGN KEY FK_402F685B2C7C2CBA");
        $this->addSql("ALTER TABLE workflow_tasks DROP FOREIGN KEY FK_E11613A3A1B4B28C");
        $this->addSql("DROP TABLE workflow_tasks");
        $this->addSql("DROP TABLE workflow_events");
        $this->addSql("DROP TABLE workflow_executions");
        $this->addSql("DROP TABLE workflow_execution_tags");
        $this->addSql("DROP TABLE workflow_tags");
        $this->addSql("DROP TABLE workflows");
        $this->addSql("DROP TABLE workflow_activity_types");
    }
}
