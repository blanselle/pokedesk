<?php

namespace App\Tests\Catalog;

use App\Catalog\CardImageDownloader;
use App\Catalog\CatalogImporter;
use App\Catalog\ImportOptions;
use App\Catalog\TcgdexClient;
use App\Command\CatalogImportCommand;
use App\Entity\Card;
use App\Entity\CardSet;
use App\Entity\CardStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogImportTest extends KernelTestCase
{
    private const API = 'https://api.tcgdex.net/v2/';
    private const ASSETS = 'https://assets.tcgdex.net/';

    private EntityManagerInterface $em;
    private string $imagesDir;

    /** @var array<string, array<string, mixed>> card id => detail returned by the fake API */
    private array $apiCards;

    /** @var list<string> ids whose detail call answers HTTP 500 */
    private array $failingCards = [];

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE card, card_set RESTART IDENTITY CASCADE');

        $this->imagesDir = sys_get_temp_dir().'/pokedesk-catalog-test-'.bin2hex(random_bytes(4));
        $this->apiCards = [
            'base1-1' => ['id' => 'base1-1', 'localId' => '1', 'name' => 'Alakazam', 'rarity' => 'Rare', 'category' => 'Pokémon', 'image' => self::ASSETS.'fr/base/base1/1', 'variants_detailed' => [['type' => 'Holo']]],
            'base1-2' => ['id' => 'base1-2', 'localId' => '2', 'name' => 'Tortank', 'rarity' => 'Rare', 'category' => 'Pokémon'],
        ];
        $this->failingCards = [];
        $this->requests = [];
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->imagesDir);
        parent::tearDown();
    }

    public function testImportsTheSetItsCardsAndTheirImages(): void
    {
        $report = $this->importer()->importSet('fr', 'base1', new ImportOptions());

        self::assertSame(2, $report->created);
        self::assertSame(0, $report->updated);
        self::assertSame(1, $report->imagesDownloaded);
        self::assertFalse($report->hasErrors());

        $set = $this->em->getRepository(CardSet::class)->findOneBy(['tcgdexId' => 'base1', 'language' => 'fr']);
        self::assertSame('Set de Base', $set->getName());
        self::assertSame('Base', $set->getSerieName());

        $card = $this->findCard('base1-1');
        self::assertSame('Alakazam', $card->getName());
        self::assertSame('1', $card->getLocalId());
        self::assertSame('Rare', $card->getRarity());
        self::assertSame(CardStatus::Active, $card->getStatus());
        self::assertFalse($card->isExcludedFromScan());
        self::assertSame('Holo', $card->getPayload()['variants_detailed'][0]['type']);
        self::assertSame('fr/base1/1.webp', $card->getImagePath());
        self::assertSame('webp-bytes', file_get_contents($this->imagesDir.'/fr/base1/1.webp'));

        // A card without image in the API is kept, but cannot be proposed by the scan.
        self::assertNull($this->findCard('base1-2')->getImagePath());
    }

    public function testRerunningUpdatesInsteadOfDuplicatingAndUsesTheCache(): void
    {
        $cache = new ArrayAdapter();
        $this->importer($cache)->importSet('fr', 'base1', new ImportOptions());
        $requestsAfterFirstRun = \count($this->requests);

        $report = $this->importer($cache)->importSet('fr', 'base1', new ImportOptions());

        self::assertSame(0, $report->created);
        self::assertSame(2, $report->updated);
        self::assertSame(1, $report->imagesSkipped);
        self::assertSame(0, $report->imagesDownloaded);
        self::assertSame(2, $this->em->getRepository(Card::class)->count([]));
        self::assertCount($requestsAfterFirstRun, $this->requests, 'The second run must not call the API again.');
    }

    public function testRefreshBypassesTheCache(): void
    {
        $cache = new ArrayAdapter();
        $this->importer($cache)->importSet('fr', 'base1', new ImportOptions());
        $requestsAfterFirstRun = \count($this->requests);

        $this->apiCards['base1-1']['name'] = 'Alakazam (renamed)';
        $this->importer($cache)->importSet('fr', 'base1', new ImportOptions(refresh: true));

        self::assertGreaterThan($requestsAfterFirstRun, \count($this->requests));
        self::assertSame('Alakazam (renamed)', $this->findCard('base1-1')->getName());
    }

    public function testACardThatCannotBeFetchedIsReportedAndTheOthersAreImported(): void
    {
        $this->failingCards = ['base1-2'];

        $report = $this->importer()->importSet('fr', 'base1', new ImportOptions());

        self::assertSame(1, $report->created);
        self::assertSame(1, $report->errorCount);
        self::assertStringContainsString('base1-2', $report->errors()[0]);
        self::assertNull($this->findCard('base1-2'));
    }

    public function testImagesAreOnlyFetchedFromTheTcgdexAssetHost(): void
    {
        $this->apiCards['base1-1']['image'] = 'https://evil.example/fr/base/base1/1';

        $report = $this->importer()->importSet('fr', 'base1', new ImportOptions());

        self::assertSame(0, $report->imagesDownloaded);
        self::assertNull($this->findCard('base1-1')->getImagePath());
        self::assertEmpty(array_filter($this->requests, static fn (string $url) => str_contains($url, 'evil.example')));
    }

    public function testImagesCanBeSkipped(): void
    {
        $report = $this->importer()->importSet('fr', 'base1', new ImportOptions(images: false));

        self::assertSame(2, $report->created);
        self::assertSame(0, $report->imagesDownloaded);
        self::assertNull($this->findCard('base1-1')->getImagePath());
    }

    public function testMaxCardsLimitsTheImport(): void
    {
        $report = $this->importer()->importSet('fr', 'base1', new ImportOptions(), maxCards: 1);

        self::assertSame(1, $report->processed);
        self::assertSame(1, $this->em->getRepository(Card::class)->count([]));
    }

    public function testCardsMissingFromTheApiBecomeObsoleteAndComeBackActive(): void
    {
        $this->importer()->importSet('fr', 'base1', new ImportOptions());
        $this->em->getConnection()->executeStatement("UPDATE card SET last_seen_at = NOW() - INTERVAL '1 day', excluded_from_scan = TRUE WHERE tcgdex_id = 'base1-1'");
        $this->em->getConnection()->executeStatement("UPDATE card SET last_seen_at = NOW() - INTERVAL '1 day'");

        $startedAt = new \DateTimeImmutable();
        unset($this->apiCards['base1-2']);
        $importer = $this->importer(new ArrayAdapter());
        $importer->importSet('fr', 'base1', new ImportOptions(images: false));

        self::assertSame(1, $importer->markNotSeenSinceAsObsolete('fr', $startedAt));
        self::assertSame(CardStatus::Obsolete, $this->findCard('base1-2')->getStatus());
        self::assertSame(CardStatus::Active, $this->findCard('base1-1')->getStatus());
        self::assertTrue($this->findCard('base1-1')->isExcludedFromScan(), 'The import must not touch the administrator flag.');

        $this->apiCards['base1-2'] = ['id' => 'base1-2', 'localId' => '2', 'name' => 'Tortank'];
        $this->importer(new ArrayAdapter())->importSet('fr', 'base1', new ImportOptions(images: false));

        self::assertSame(CardStatus::Active, $this->findCard('base1-2')->getStatus());
    }

    public function testCommandImportsTheRequestedSet(): void
    {
        $tester = $this->commandTester();

        $status = $tester->execute(['--lang' => ['fr'], '--set' => ['base1'], '--no-images' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Created', $tester->getDisplay());
        self::assertSame(2, $this->em->getRepository(Card::class)->count([]));
    }

    public function testPokemonTcgPocketSetsAreNeverImported(): void
    {
        $report = $this->importer()->importSet('fr', 'A1', new ImportOptions());

        self::assertSame(1, $report->setsExcluded);
        self::assertSame(0, $report->processed);
        self::assertSame(0, $this->em->getRepository(CardSet::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Card::class)->count([]));
        self::assertEmpty(array_filter($this->requests, static fn (string $url) => str_contains($url, 'A1-001')), 'The cards of an excluded set must not even be fetched.');
    }

    public function testCommandSkipsPocketSetsEvenWhenAskedExplicitly(): void
    {
        $tester = $this->commandTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--lang' => ['fr'], '--set' => ['A1'], '--no-images' => true]));
        self::assertSame(0, $this->em->getRepository(CardSet::class)->count([]));

        self::assertSame(Command::SUCCESS, $tester->execute(['--lang' => ['fr'], '--no-images' => true]));
        self::assertStringContainsString('Sets excluded', $tester->getDisplay());
        self::assertSame(1, $this->em->getRepository(CardSet::class)->count([]), 'Only the physical set is imported.');
    }

    public function testCommandFailsWhenACardCannotBeFetched(): void
    {
        $this->failingCards = ['base1-2'];

        self::assertSame(Command::FAILURE, $this->commandTester()->execute(['--lang' => ['fr'], '--no-images' => true]));
    }

    public function testCommandRejectsAnInvalidLanguage(): void
    {
        self::assertSame(Command::INVALID, $this->commandTester()->execute(['--lang' => ['../etc']]));
    }

    private function commandTester(): CommandTester
    {
        $cache = new ArrayAdapter();
        $command = new CatalogImportCommand($this->tcgdex($cache), $this->importer($cache));

        return new CommandTester((new Command('app:catalog:import'))->setCode($command));
    }

    private function importer(?ArrayAdapter $cache = null): CatalogImporter
    {
        $cache ??= new ArrayAdapter();

        return new CatalogImporter(
            $this->tcgdex($cache),
            new CardImageDownloader($this->httpClient(), new Filesystem(), $this->imagesDir),
            $this->em,
            $this->em->getRepository(Card::class),
            $this->em->getRepository(CardSet::class),
        );
    }

    private function tcgdex(ArrayAdapter $cache): TcgdexClient
    {
        return new TcgdexClient($this->httpClient(), $cache, new NullLogger());
    }

    private function httpClient(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $url;
            $json = ['response_headers' => ['content-type: application/json']];

            if (self::API.'fr/sets' === $url) {
                return new MockResponse(json_encode([['id' => 'base1', 'name' => 'Set de Base'], ['id' => 'A1', 'name' => 'Puissance Génétique']]), $json);
            }

            // A Pokémon TCG Pocket set (digital-only series).
            if (self::API.'fr/sets/A1' === $url) {
                return new MockResponse(json_encode([
                    'id' => 'A1',
                    'name' => 'Puissance Génétique',
                    'serie' => ['id' => 'tcgp', 'name' => 'Pocket'],
                    'cards' => [['id' => 'A1-001']],
                ]), $json);
            }

            if (self::API.'fr/sets/base1' === $url) {
                return new MockResponse(json_encode([
                    'id' => 'base1',
                    'name' => 'Set de Base',
                    'serie' => ['id' => 'base', 'name' => 'Base'],
                    'releaseDate' => '1999-01-09',
                    'cardCount' => ['total' => 102],
                    'cards' => array_map(static fn (string $id) => ['id' => $id], array_keys($this->apiCards)),
                ]), $json);
            }

            if (preg_match('#^'.preg_quote(self::API.'fr/cards/', '#').'(.+)$#', $url, $m)) {
                if (\in_array($m[1], $this->failingCards, true)) {
                    return new MockResponse('error', ['http_code' => 500]);
                }

                return isset($this->apiCards[$m[1]])
                    ? new MockResponse(json_encode($this->apiCards[$m[1]]), $json)
                    : new MockResponse('{}', ['http_code' => 404]);
            }

            if (str_starts_with($url, self::ASSETS) && str_ends_with($url, '/high.webp')) {
                return new MockResponse('webp-bytes', ['response_headers' => ['content-type: image/webp']]);
            }

            return new MockResponse('', ['http_code' => 404]);
        }, self::API);
    }

    private function findCard(string $tcgdexId): ?Card
    {
        $this->em->clear();

        return $this->em->getRepository(Card::class)->findOneBy(['tcgdexId' => $tcgdexId, 'language' => 'fr']);
    }
}
