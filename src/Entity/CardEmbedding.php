<?php

namespace App\Entity;

use App\Repository\CardEmbeddingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Pgvector\Vector;

/** Visual fingerprint of a catalogue card image, computed by the vision service. */
#[ORM\Entity(repositoryClass: CardEmbeddingRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_card_embedding_card_model', columns: ['card_id', 'model'])]
class CardEmbedding
{
    // Size of the vectors of the current model (CLIP ViT-B/32).
    public const DIMENSION = 512;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Card $card,
        // Model name and version: vectors of different models are never compared.
        #[ORM\Column(length: 50)]
        private string $model,
        // The column definition is explicit because Doctrine cannot read the length of a vector back from PostgreSQL.
        #[ORM\Column(type: 'vector', columnDefinition: 'vector('.self::DIMENSION.') NOT NULL')]
        private Vector $embedding,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCard(): Card
    {
        return $this->card;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getEmbedding(): Vector
    {
        return $this->embedding;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
