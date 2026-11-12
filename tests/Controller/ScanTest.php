<?php

namespace App\Tests\Controller;

use App\Entity\Card;
use App\Entity\CardSet;
use App\Entity\StockItem;
use App\Entity\User;
use App\Repository\CardEmbeddingRepository;
use App\Tests\Support\FakeVision;
use App\Vision\ScanResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ScanTest extends WebTestCase
{
    // A valid 1x1 PNG: the fake vision service does not read it, but the upload is validated.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CardEmbeddingRepository $embeddings;
    private FakeVision $vision;
    private User $user;
    private string $photo;

    /** @var array<string, Card> */
    private array $cards = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The fake vision service keeps its state in the container: do not rebuild it between requests.
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->embeddings = $container->get(CardEmbeddingRepository::class);
        $this->vision = $container->get(FakeVision::class);
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE stock_item, card_embedding, card, card_set, reset_password_request, app_user RESTART IDENTITY CASCADE');

        $this->user = $this->createUser('alice@example.com');
        $this->client->loginUser($this->user);

        $this->photo = tempnam(sys_get_temp_dir(), 'scan').'.png';
        file_put_contents($this->photo, base64_decode(self::PNG));

        $this->addCard('fr', 'base1-1', 'Alakazam', 'alakazam');
        $this->addCard('fr', 'base1-2', 'Tortank', 'tortank');
        $this->addCard('fr', 'base1-3', 'Dracaufeu', 'dracaufeu');
        $this->addCard('en', 'base1-1', 'Alakazam', 'alakazam');
    }

    protected function tearDown(): void
    {
        @unlink($this->photo);
        parent::tearDown();
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        static::ensureKernelShutdown();
        $anonymous = static::createClient();

        foreach (['/scan', '/stock', '/cards/1/image', '/scan/'.str_repeat('a', 32).'/summary'] as $url) {
            $anonymous->request('GET', $url);
            self::assertResponseRedirects('/login', null, $url);
        }
    }

    public function testFullScanAddsTheValidatedCardsToTheStock(): void
    {
        $this->vision->scanResult = new ScanResult(FakeVision::MODEL, [
            FakeVision::detected('alakazam'),
            FakeVision::detected('tortank'),
            FakeVision::detected('something-unknown'),
        ]);

        $crawler = $this->startScan();
        self::assertStringContainsString('/card/0', $this->client->getRequest()->getUri());
        self::assertSelectorTextContains('h1', 'Carte 1 sur 2');

        // The best candidate is preselected, so validating takes one action.
        self::assertSame((string) $this->cards['fr/base1-1']->getId(), $crawler->filter('input.btn-check:checked')->attr('value'));
        self::assertSelectorTextContains('.candidate-tile', 'Alakazam');
        $crawler = $this->client->submit($crawler->selectButton('Valider cette carte')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Carte 2 sur 2');
        $this->client->submit($crawler->selectButton('Valider cette carte')->form());
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('/summary', $this->client->getRequest()->getUri());
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('body', 'Non reconnue');

        $form = $crawler->selectButton('Ajouter à mon stock')->form(['form[quantity_'.$this->cards['fr/base1-1']->getId().']' => 2]);
        $this->client->submit($form);

        self::assertResponseRedirects('/stock');
        self::assertSame(['base1-1' => 2, 'base1-2' => 1], $this->stockByCard());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '3 carte(s) ajoutée(s)');
        self::assertSelectorTextContains('body', 'Alakazam');
    }

    public function testTheUserCanPickAnotherCandidateOrSayNoneMatches(): void
    {
        // The photographed card looks like Alakazam, and a bit like Tortank.
        $this->vision->scanResult = new ScanResult(FakeVision::MODEL, [FakeVision::detected('alakazam'), FakeVision::detected('tortank')]);

        $crawler = $this->startScan();
        $this->client->submit($crawler->selectButton('Aucune ne correspond')->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Valider cette carte')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Passée');
        self::assertCount(1, $crawler->filter('tbody tr'));
        $this->client->submit($crawler->selectButton('Ajouter à mon stock')->form());

        self::assertSame(['base1-2' => 1], $this->stockByCard());
    }

    public function testNothingIsAddedWhenNothingIsValidated(): void
    {
        $this->vision->scanResult = new ScanResult(FakeVision::MODEL, [FakeVision::detected('something-unknown')]);

        $this->startScan();

        self::assertStringContainsString('/summary', $this->client->getRequest()->getUri());
        self::assertSelectorTextContains('body', 'Non reconnue');
        self::assertSelectorExists('button[disabled]');
        self::assertSame([], $this->stockByCard());
    }

    public function testProposalsAreLimitedToTheChosenLanguage(): void
    {
        $this->vision->scanResult = new ScanResult(FakeVision::MODEL, [FakeVision::detected('alakazam')]);

        $this->startScan('en');

        self::assertCount(1, $this->client->getCrawler()->filter('input.btn-check'));
        self::assertCount(1, $this->client->getCrawler()->filter('input[type=radio]'));
        self::assertSelectorTextContains('.candidate-tile', 'Alakazam');
        self::assertSame((string) $this->cards['en/base1-1']->getId(), $this->client->getCrawler()->filter('input.btn-check')->attr('value'));
    }

    public function testObsoleteAndExcludedCardsAreNeverProposed(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE card SET status = 'obsolete' WHERE tcgdex_id = 'base1-2'");
        $this->em->getConnection()->executeStatement('UPDATE card SET excluded_from_scan = TRUE WHERE tcgdex_id = \'base1-3\'');
        $this->vision->scanResult = new ScanResult(FakeVision::MODEL, [FakeVision::detected('tortank'), FakeVision::detected('dracaufeu')]);

        $this->startScan();

        // Neither card can be proposed: both are unrecognised and the scan goes straight to the summary.
        self::assertStringContainsString('/summary', $this->client->getRequest()->getUri());
        self::assertSelectorNotExists('tbody tr');
    }

    public function testAnUpsideDownCardIsRecognisedAndShownTheRightWayUp(): void
    {
        $this->vision->scanResult = new ScanResult(FakeVision::MODEL, [FakeVision::detected('no-match-upright', 'alakazam')]);

        $crawler = $this->startScan();

        self::assertSelectorTextContains('.candidate-tile', 'Alakazam');
        self::assertCount(1, $crawler->filter('img.upside-down'));
    }

    public function testScannedCardsAreAddedToExistingStockLines(): void
    {
        foreach ([1, 2] as $_) {
            $this->vision->scanResult = new ScanResult(FakeVision::MODEL, [FakeVision::detected('alakazam')]);
            $crawler = $this->startScan();
            $crawler = $this->client->submit($crawler->selectButton('Valider cette carte')->form());
            $crawler = $this->client->followRedirect();
            $this->client->submit($crawler->selectButton('Ajouter à mon stock')->form());
        }

        self::assertSame(['base1-1' => 2], $this->stockByCard());
        self::assertSame(1, $this->em->getRepository(StockItem::class)->count([]));
    }

    public function testSubmittingTheSummaryTwiceAddsOnlyOnce(): void
    {
        $this->vision->scanResult = new ScanResult(FakeVision::MODEL, [FakeVision::detected('alakazam')]);
        $crawler = $this->startScan();
        $this->client->submit($crawler->selectButton('Valider cette carte')->form());
        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Ajouter à mon stock')->form();

        $this->client->submit($form);
        $this->client->submit($form);

        self::assertResponseRedirects('/scan');
        self::assertSame(['base1-1' => 1], $this->stockByCard());
    }

    public function testStartingANewScanReplacesTheOneInProgress(): void
    {
        $this->vision->scanResult = new ScanResult(FakeVision::MODEL, [FakeVision::detected('alakazam')]);
        $this->startScan();
        $first = $this->client->getRequest()->getUri();

        $this->startScan();

        self::assertNotSame($first, $this->client->getRequest()->getUri());
        $this->client->request('GET', $first);
        self::assertResponseRedirects('/scan');
    }

    public function testAScanIsPrivateToItsOwner(): void
    {
        $this->vision->scanResult = new ScanResult(FakeVision::MODEL, [FakeVision::detected('alakazam')]);
        $this->startScan();
        $card = $this->client->getRequest()->getUri();
        $crop = str_replace('/card/0', '/crop/0', $card);

        $this->client->request('GET', $crop);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'image/jpeg');
        self::assertSame('jpeg-bytes', $this->client->getResponse()->getContent());

        $this->client->loginUser($this->createUser('bob@example.com'));
        $this->client->request('GET', $card);
        self::assertResponseRedirects('/scan');
        $this->client->request('GET', $crop);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnavailableVisionServiceIsReportedAndNothingIsKept(): void
    {
        $this->vision->unavailable = true;

        $this->startScan();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'momentanément indisponible');
    }

    public function testAPhotoWithoutCardIsReported(): void
    {
        $this->vision->scanResult = new ScanResult(FakeVision::MODEL, []);

        $this->startScan();

        self::assertSelectorTextContains('.alert-warning', 'Aucune carte');
    }

    public function testTheUploadMustBeAnImage(): void
    {
        $text = tempnam(sys_get_temp_dir(), 'scan').'.txt';
        file_put_contents($text, 'not an image');

        $this->client->request('GET', '/scan');
        $this->client->submitForm('Lancer la reconnaissance', ['scan_upload[language]' => 'fr', 'scan_upload[photo]' => $text]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Format non pris en charge');
        unlink($text);
    }

    public function testTheStockPageOnlyShowsTheUsersOwnCards(): void
    {
        $this->addStock($this->user, 'fr/base1-1', 3);
        $this->addStock($this->createUser('bob@example.com'), 'fr/base1-2', 5);

        $this->client->request('GET', '/stock');

        self::assertSelectorTextContains('body', 'Alakazam');
        self::assertSame('3', $this->client->getCrawler()->filter('input[name$="[quantity]"]')->attr('value'));
        self::assertSelectorTextNotContains('body', 'Tortank');
    }

    public function testAnEmptyStockInvitesToScan(): void
    {
        $this->client->request('GET', '/stock');

        self::assertSelectorTextContains('body', 'Votre stock est vide');
        self::assertSelectorExists('a[href="/scan"]');
    }

    public function testTheScanPageExplainsWhenTheCatalogueIsNotReady(): void
    {
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE card_embedding');

        $this->client->request('GET', '/scan');

        self::assertSelectorTextContains('.alert-warning', 'pas encore prêt');
        self::assertSelectorNotExists('form');
    }

    private function startScan(string $language = 'fr'): Crawler
    {
        $this->client->request('GET', '/scan');
        $this->client->submitForm('Lancer la reconnaissance', ['scan_upload[language]' => $language, 'scan_upload[photo]' => $this->photo]);

        return $this->client->isFollowingRedirects() ? $this->client->getCrawler() : $this->followIfRedirect();
    }

    private function followIfRedirect(): Crawler
    {
        return $this->client->getResponse()->isRedirect() ? $this->client->followRedirect() : $this->client->getCrawler();
    }

    private function addCard(string $language, string $id, string $name, string $seed): void
    {
        $key = $language.'/'.$seed;
        $set = $this->em->getRepository(CardSet::class)->findOneBy(['tcgdexId' => 'base1', 'language' => $language]);
        if (null === $set) {
            $set = new CardSet('base1', $language);
            $set->refreshFrom(['name' => 'Set de Base']);
            $this->em->persist($set);
        }

        $card = new Card($id, $language, $set);
        $card->refreshFrom(['localId' => substr($id, 6), 'name' => $name], $set, new \DateTimeImmutable());
        $this->em->persist($card);
        $this->em->flush();

        $this->embeddings->save(FakeVision::MODEL, [$card->getId() => FakeVision::vector($seed)]);
        $this->cards[$language.'/'.$id] = $card;
    }

    private function addStock(User $user, string $cardKey, int $quantity): void
    {
        $this->em->persist(new StockItem($user, $this->cards[$cardKey], $quantity));
        $this->em->flush();
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setIsVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return array<string, int> TCGdex id => quantity, for the main user */
    private function stockByCard(): array
    {
        $rows = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT c.tcgdex_id, s.quantity FROM stock_item s JOIN card c ON c.id = s.card_id WHERE s.user_id = ? AND c.language = \'fr\' ORDER BY c.tcgdex_id',
            [$this->user->getId()],
        );

        return array_map('intval', $rows);
    }
}
