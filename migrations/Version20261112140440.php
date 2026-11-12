<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112140440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete the digital-only Pokémon TCG Pocket (tcgp) sets and cards, no longer imported';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM card WHERE set_id IN (SELECT id FROM card_set WHERE serie_id = 'tcgp')");
        $this->addSql("DELETE FROM card_set WHERE serie_id = 'tcgp'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The deleted Pokémon TCG Pocket data is not restored; the import excludes that series.');
    }
}
