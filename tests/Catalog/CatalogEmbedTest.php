<?php

namespace App\Tests\Catalog;

use App\Entity\Card;
use App\Entity\CardSet;
use App\Repository\CardEmbeddingRepository;
use App\Tests\Support\FakeVision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class CatalogEmbedTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CardEmbeddingRepository $embeddings;
    private FakeVision $vision;
    private string $imagesDir;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->embeddings = $container->get(CardEmbeddingRepository::class);
        $this->vision = $container->get(FakeVision::class);
        $this->imagesDir = $container->getParameter('app.catalog_images_dir');
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE card_embedding, card, card_set RESTART IDENTITY CASCADE');

        $this->tester = new CommandTester((new Application($kernel))->find('app:catalog:embed'));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(\dirname($this->imagesDir));
        parent::tearDown();
    }

    public function testComputesAndStoresTheFingerprintOfEachImage(): void
    {
        $this->addCard('base1-1', 'fr', 'one');
        $this->addCard('base1-2', 'fr', 'two');

        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(2, $this->embeddings->countForModel(FakeVision::MODEL));

        $stored = $this->em->getConnection()->fetchOne('SELECT embedding::text FROM card_embedding ORDER BY card_id LIMIT 1');
        self::assertEqualsWithDelta(FakeVision::vector('one'), json_decode($stored, true), 1e-5);
    }

    public function testOnlyMissingFingerprintsAreComputed(): void
    {
        $this->addCard('base1-1', 'fr', 'one');
        $this->tester->execute([]);
        $this->addCard('base1-2', 'fr', 'two');
        $this->vision->embedCalls = [];

        $this->tester->execute([]);

        self::assertSame([['two']], $this->vision->embedCalls);
        self::assertSame(2, $this->embeddings->countForModel(FakeVision::MODEL));
    }

    public function testCardsWithoutImageObsoleteOrOutsideTheRequestedLanguagesAreSkipped(): void
    {
        $this->addCard('base1-1', 'fr', 'one');
        $this->addCard('base1-1', 'en', 'one-en');
        $this->addCard('base1-3', 'fr', null);
        $this->addCard('base1-4', 'fr', 'obsolete');
        $this->em->getConnection()->executeStatement("UPDATE card SET status = 'obsolete' WHERE tcgdex_id = 'base1-4'");

        $this->tester->execute(['--lang' => ['fr']]);

        self::assertSame(1, $this->embeddings->countForModel(FakeVision::MODEL));
    }

    public function testLimitAndBatchSize(): void
    {
        foreach (range(1, 5) as $i) {
            $this->addCard('base1-'.$i, 'fr', 'image-'.$i);
        }

        $this->tester->execute(['--limit' => 3, '--batch' => 2]);

        self::assertSame(3, $this->embeddings->countForModel(FakeVision::MODEL));
        self::assertSame([2, 1], array_map('count', $this->vision->embedCalls));
    }

    public function testAnUnreadableImageIsReportedAndTheOthersAreStillStored(): void
    {
        $this->addCard('base1-1', 'fr', 'one');
        $this->addCard('base1-2', 'fr', 'corrupt');
        $this->addCard('base1-3', 'fr', 'three');

        $status = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(2, $this->embeddings->countForModel(FakeVision::MODEL));
        self::assertStringContainsString('Unreadable image', $this->tester->getDisplay());
    }

    public function testAMissingImageFileIsReported(): void
    {
        $this->addCard('base1-1', 'fr', 'one');
        unlink($this->imagesDir.'/fr/set/base1-1.webp');

        self::assertSame(Command::FAILURE, $this->tester->execute([]));
        self::assertStringContainsString('image file not found', $this->tester->getDisplay());
    }

    public function testRejectsInvalidArguments(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute(['--lang' => ['../x']]));
        self::assertSame(Command::INVALID, $this->tester->execute(['--batch' => 500]));
    }

    private function addCard(string $id, string $language, ?string $imageContent): void
    {
        $set = $this->em->getRepository(CardSet::class)->findOneBy(['tcgdexId' => 'set', 'language' => $language]);
        if (null === $set) {
            $set = new CardSet('set', $language);
            $set->refreshFrom(['name' => 'Set']);
            $this->em->persist($set);
        }

        $card = new Card($id, $language, $set);
        $card->refreshFrom(['localId' => $id, 'name' => 'Card '.$id], $set, new \DateTimeImmutable());

        if (null !== $imageContent) {
            $path = $language.'/set/'.$id.'.webp';
            (new Filesystem())->dumpFile($this->imagesDir.'/'.$path, $imageContent);
            $card->setImagePath($path);
        }

        $this->em->persist($card);
        $this->em->flush();
    }
}
