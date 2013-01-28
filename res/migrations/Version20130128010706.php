<?php

namespace Scrutinizer\Workflow\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration,
    Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated Migration: Please modify to your need!
 */
class Version20130128010706 extends AbstractMigration
{
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql", "Migration can only be executed safely on 'mysql'.");
        
        $this->addSql("ALTER TABLE workflow_tasks ADD childPolicy VARCHAR(20) DEFAULT NULL, ADD childWorkflowExecution_id BIGINT UNSIGNED DEFAULT NULL");
        $this->addSql("UPDATE workflow_tasks t SET childWorkflowExecution_id = (SELECT e.id FROM workflow_executions e WHERE e.parentWorkflowExecutionTask_id = t.id)");
        $this->addSql("ALTER TABLE workflow_tasks ADD CONSTRAINT FK_E11613A3C5B99D7D FOREIGN KEY (childWorkflowExecution_id) REFERENCES workflow_executions (id)");
        $this->addSql("CREATE INDEX IDX_E11613A3C5B99D7D ON workflow_tasks (childWorkflowExecution_id)");
        $this->addSql("ALTER TABLE workflow_executions DROP FOREIGN KEY FK_402F685B65CFB74D");
        $this->addSql("DROP INDEX UNIQ_402F685B65CFB74D ON workflow_executions");
        $this->addSql("UPDATE workflow_tasks t SET childWorkflowExecution_id = (SELECT e.id FROM workflow_executions e WHERE e.parentWorkflowExecutionTask_id = t.id)");
        $this->addSql("ALTER TABLE workflow_executions DROP parentWorkflowExecutionTask_id");
    }

    public function down(Schema $schema)
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql", "Migration can only be executed safely on 'mysql'.");
        
        $this->addSql("ALTER TABLE workflow_executions ADD parentWorkflowExecutionTask_id BIGINT UNSIGNED DEFAULT NULL");
        $this->addSql("UPDATE workflow_executions e SET parentWorkflowExecutionTask_id = (SELECT t.id FROM workflow_tasks t WHERE t.childWorkflowExecution_id = e.id)");
        $this->addSql("ALTER TABLE workflow_executions ADD CONSTRAINT FK_402F685B65CFB74D FOREIGN KEY (parentWorkflowExecutionTask_id) REFERENCES workflow_tasks (id)");
        $this->addSql("CREATE UNIQUE INDEX UNIQ_402F685B65CFB74D ON workflow_executions (parentWorkflowExecutionTask_id)");
        $this->addSql("ALTER TABLE workflow_tasks DROP FOREIGN KEY FK_E11613A3C5B99D7D");
        $this->addSql("DROP INDEX IDX_E11613A3C5B99D7D ON workflow_tasks");
        $this->addSql("ALTER TABLE workflow_tasks DROP childPolicy, DROP childWorkflowExecution_id");
    }
}
