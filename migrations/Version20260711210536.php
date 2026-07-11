<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711210536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expedition: tabela profiles (profil kapitana jako snapshot JSON, wzór games)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE profiles (id UUID NOT NULL, state JSON NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN profiles.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN profiles.created_at IS \'(DC2Type:datetimetz_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE profiles');
    }
}
