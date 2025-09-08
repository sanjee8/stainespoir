<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250908164722 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE attendance (id INT AUTO_INCREMENT NOT NULL, child_id INT NOT NULL, date DATE NOT NULL, status VARCHAR(16) NOT NULL, notes LONGTEXT DEFAULT NULL, INDEX IDX_6DE30D91DD62C21B (child_id), UNIQUE INDEX UNIQ_6DE30D91DD62C21BAA9E377A (child_id, date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE message (id INT AUTO_INCREMENT NOT NULL, child_id INT NOT NULL, subject VARCHAR(160) NOT NULL, body LONGTEXT NOT NULL, sender VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', read_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_B6BD307FDD62C21B (child_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE outing (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(160) NOT NULL, starts_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', location VARCHAR(160) DEFAULT NULL, description LONGTEXT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE outing_registration (id INT AUTO_INCREMENT NOT NULL, child_id INT NOT NULL, outing_id INT NOT NULL, status VARCHAR(16) NOT NULL, notes LONGTEXT DEFAULT NULL, INDEX IDX_5DD5A124DD62C21B (child_id), INDEX IDX_5DD5A124AF4C7531 (outing_id), UNIQUE INDEX UNIQ_5DD5A124DD62C21BAF4C7531 (child_id, outing_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE attendance ADD CONSTRAINT FK_6DE30D91DD62C21B FOREIGN KEY (child_id) REFERENCES child (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FDD62C21B FOREIGN KEY (child_id) REFERENCES child (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE outing_registration ADD CONSTRAINT FK_5DD5A124DD62C21B FOREIGN KEY (child_id) REFERENCES child (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE outing_registration ADD CONSTRAINT FK_5DD5A124AF4C7531 FOREIGN KEY (outing_id) REFERENCES outing (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendance DROP FOREIGN KEY FK_6DE30D91DD62C21B');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307FDD62C21B');
        $this->addSql('ALTER TABLE outing_registration DROP FOREIGN KEY FK_5DD5A124DD62C21B');
        $this->addSql('ALTER TABLE outing_registration DROP FOREIGN KEY FK_5DD5A124AF4C7531');
        $this->addSql('DROP TABLE attendance');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE outing');
        $this->addSql('DROP TABLE outing_registration');
    }
}
