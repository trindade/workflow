<?php

namespace Scrutinizer\Workflow\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration,
    Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated Migration: Please modify to your need!
 */
class Version20130202201843 extends AbstractMigration
{
    public function up(Schema $schema)
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql", "Migration can only be executed safely on 'mysql'.");
        
        $this->addSql("ALTER TABLE workflow_tasks ADD maxRuntime INT UNSIGNED DEFAULT NULL, ADD startedAt DATETIME DEFAULT NULL, ADD machineIdentifier VARCHAR(50) DEFAULT NULL, ADD workerIdentifier VARCHAR(50) DEFAULT NULL");
        $this->addSql("ALTER TABLE workflow_activity_types ADD maxRuntime INT UNSIGNED NOT NULL");
    }

    public function down(Schema $schema)
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql", "Migration can only be executed safely on 'mysql'.");
        
        $this->addSql("ALTER TABLE workflow_activity_types DROP maxRuntime");
        $this->addSql("ALTER TABLE workflow_tasks DROP maxRuntime, DROP startedAt, DROP machineIdentifier, DROP workerIdentifier");
    }
}
