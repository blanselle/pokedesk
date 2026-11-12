<?php

namespace App\Entity;

use App\Repository\CardSetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CardSetRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_card_set_tcgdex_language', columns: ['tcgdex_id', 'language'])]
class CardSet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $serieId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $serieName = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releaseDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $cardCountTotal = null;

    public function __construct(
        #[ORM\Column(length: 64)]
        private readonly string $tcgdexId,
        #[ORM\Column(length: 10)]
        private readonly string $language,
    ) {
    }

    /** @param array<string, mixed> $data A TCGdex set detail */
    public function refreshFrom(array $data): void
    {
        $this->name = (string) ($data['name'] ?? $this->tcgdexId);
        $this->serieId = isset($data['serie']['id']) ? (string) $data['serie']['id'] : null;
        $this->serieName = isset($data['serie']['name']) ? (string) $data['serie']['name'] : null;
        $this->cardCountTotal = isset($data['cardCount']['total']) ? (int) $data['cardCount']['total'] : null;

        try {
            $this->releaseDate = isset($data['releaseDate']) ? new \DateTimeImmutable((string) $data['releaseDate']) : null;
        } catch (\Exception) {
            $this->releaseDate = null;
        }
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getSerieId(): ?string
    {
        return $this->serieId;
    }

    public function getSerieName(): ?string
    {
        return $this->serieName;
    }

    public function getReleaseDate(): ?\DateTimeImmutable
    {
        return $this->releaseDate;
    }

    public function getCardCountTotal(): ?int
    {
        return $this->cardCountTotal;
    }
}
