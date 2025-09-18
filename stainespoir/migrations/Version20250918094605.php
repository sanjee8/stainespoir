<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250918094605 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE insurance (id INT AUTO_INCREMENT NOT NULL, child_id INT NOT NULL, validated_by_id INT DEFAULT NULL, type VARCHAR(16) NOT NULL, school_year VARCHAR(9) NOT NULL, path VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, uploaded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(16) NOT NULL, validated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', admin_comment LONGTEXT DEFAULT NULL, INDEX IDX_640EAF4CDD62C21B (child_id), INDEX IDX_640EAF4CC69DE5E5 (validated_by_id), UNIQUE INDEX uniq_child_type_year (child_id, type, school_year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE insurance ADD CONSTRAINT FK_640EAF4CDD62C21B FOREIGN KEY (child_id) REFERENCES child (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE insurance ADD CONSTRAINT FK_640EAF4CC69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES app_user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE insurance DROP FOREIGN KEY FK_640EAF4CDD62C21B');
        $this->addSql('ALTER TABLE insurance DROP FOREIGN KEY FK_640EAF4CC69DE5E5');
        $this->addSql('DROP TABLE insurance');
    }
}
