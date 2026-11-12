<?php

namespace App\Entity;

use App\Repository\CardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A card in one language: the same card in another language is another row. */
#[ORM\Entity(repositoryClass: CardRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_card_tcgdex_language', columns: ['tcgdex_id', 'language'])]
#[ORM\Index(name: 'idx_card_language_status', columns: ['language', 'status'])]
class Card
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $localId = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $rarity = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $category = null;

    // Base URL from the API, without quality or extension suffix.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageUrl = null;

    // Relative to the catalogue images directory.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column(length: 20, enumType: CardStatus::class)]
    private CardStatus $status = CardStatus::Active;

    // Set by an administrator, never touched by the import.
    #[ORM\Column]
    private bool $excludedFromScan = false;

    // Full API answer: variants, prices and anything not mapped to a column yet.
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(
        #[ORM\Column(length: 64)]
        private readonly string $tcgdexId,
        #[ORM\Column(length: 10)]
        private readonly string $language,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private CardSet $set,
    ) {
        $this->createdAt = $this->lastSeenAt = new \DateTimeImmutable();
    }

    /** @param array<string, mixed> $detail A TCGdex card detail */
    public function refreshFrom(array $detail, CardSet $set, \DateTimeImmutable $seenAt): void
    {
        $this->set = $set;
        $this->localId = (string) ($detail['localId'] ?? '');
        $this->name = (string) ($detail['name'] ?? $this->tcgdexId);
        $this->rarity = isset($detail['rarity']) ? (string) $detail['rarity'] : null;
        $this->category = isset($detail['category']) ? (string) $detail['category'] : null;
        $this->imageUrl = isset($detail['image']) ? (string) $detail['image'] : null;
        $this->payload = $detail;
        $this->status = CardStatus::Active;
        $this->lastSeenAt = $seenAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTcgdexId(): string
    {
        return $this->tcgdexId;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getSet(): CardSet
    {
        return $this->set;
    }

    public function getLocalId(): string
    {
        return $this->localId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRarity(): ?string
    {
        return $this->rarity;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): void
    {
        $this->imagePath = $imagePath;
    }

    public function getStatus(): CardStatus
    {
        return $this->status;
    }

    public function isExcludedFromScan(): bool
    {
        return $this->excludedFromScan;
    }

    public function setExcludedFromScan(bool $excludedFromScan): void
    {
        $this->excludedFromScan = $excludedFromScan;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
