<?php

namespace App\Tests\Controller;

use App\Entity\Card;
use App\Entity\CardSet;
use App\Entity\StockItem;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class StockTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    /** @var array<string, StockItem> */
    private array $lines = [];
    private int $cardCount = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE stock_item, card_embedding, card, card_set, reset_password_request, app_user RESTART IDENTITY CASCADE');

        $this->user = $this->createUser('alice@example.com');
        $this->client->loginUser($this->user);

        $this->lines['Alakazam'] = $this->addLine($this->user, 'Alakazam', 3);
        $this->lines['Tortank'] = $this->addLine($this->user, 'Tortank', 1);
        $this->lines['Dracaufeu'] = $this->addLine($this->user, 'Dracaufeu', 2);
    }

    public function testEachLineHasItsQuantityControls(): void
    {
        $crawler = $this->client->request('GET', '/stock');

        $id = $this->lines['Alakazam']->getId();
        self::assertSame('3', $crawler->filter(\sprintf('input[name="line_%d[quantity]"]', $id))->attr('value'));
        self::assertCount(3, $crawler->filter('input[type=checkbox][form="stock-selection"]'));
    }

    public function testAddingAndRemovingOneCopy(): void
    {
        $crawler = $this->client->request('GET', '/stock');
        $this->press($crawler, 'Alakazam', 'more');
        self::assertResponseRedirects('/stock?page=1');
        self::assertSame(4, $this->quantity('Alakazam'));

        $crawler = $this->client->request('GET', '/stock');
        $this->press($crawler, 'Alakazam', 'less');
        self::assertSame(3, $this->quantity('Alakazam'));
    }

    public function testTypingAQuantity(): void
    {
        $crawler = $this->client->request('GET', '/stock');
        $this->press($crawler, 'Alakazam', 'apply', 10);

        self::assertSame(10, $this->quantity('Alakazam'));
    }

    public function testConcurrentChangesAreNotLost(): void
    {
        $crawler = $this->client->request('GET', '/stock');
        // Another action (e.g. a scan) raises the line from 3 to 7 while the page is open.
        $this->em->getConnection()->executeStatement('UPDATE stock_item SET quantity = 7 WHERE id = ?', [$this->lines['Alakazam']->getId()]);

        $this->press($crawler, 'Alakazam', 'apply', 5);

        // The user asked for two more copies than they saw.
        self::assertSame(9, $this->quantity('Alakazam'));
    }

    public function testAChangeThatWouldEmptyTheLineBehindTheUsersBackIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/stock');
        $this->em->getConnection()->executeStatement('UPDATE stock_item SET quantity = 1 WHERE id = ?', [$this->lines['Alakazam']->getId()]);

        $this->press($crawler, 'Alakazam', 'apply', 2);
        $this->client->followRedirect();

        self::assertSame(1, $this->quantity('Alakazam'));
        self::assertSelectorTextContains('.alert-warning', 'vient d\'être modifiée');
    }

    public function testQuantityOutOfRangeIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/stock');
        $this->press($crawler, 'Alakazam', 'apply', 10000);
        $this->client->followRedirect();

        self::assertSame(3, $this->quantity('Alakazam'));
        self::assertSelectorTextContains('.alert-warning', 'Quantité invalide');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('zeroingActions')]
    public function testGoingDownToZeroAsksForConfirmationBeforeDeleting(string $button, ?int $typed): void
    {
        $crawler = $this->client->request('GET', '/stock');
        $this->press($crawler, 'Tortank', $button, $typed);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Retirer cette carte');
        self::assertSelectorTextContains('body', 'Tortank');
        self::assertSame(1, $this->quantity('Tortank'));

        $this->client->submit($this->client->getCrawler()->selectButton('Confirmer la suppression')->form());
        $this->client->followRedirect();

        self::assertNull($this->quantity('Tortank'));
        self::assertSelectorTextContains('.alert-success', '1 ligne supprimée');
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function zeroingActions(): iterable
    {
        yield 'minus button' => ['less', null];
        yield 'typed zero' => ['apply', 0];
    }

    public function testSeveralLinesAreDeletedAfterConfirmation(): void
    {
        $crawler = $this->client->request('GET', '/stock');
        $ids = [$this->lines['Alakazam']->getId(), $this->lines['Dracaufeu']->getId()];
        $token = $crawler->filter('#stock-selection input[name="stock_deletion[_token]"]')->attr('value');

        $crawler = $this->client->request('POST', '/stock/delete?page=1', ['stock_deletion' => ['ids' => $ids, '_token' => $token, 'delete' => '']]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Retirer ces cartes');
        self::assertSelectorTextContains('body', '2 lignes et 5 exemplaires');
        self::assertSame(3, $this->lineCount());

        $this->client->submit($crawler->selectButton('Confirmer la suppression')->form());
        self::assertResponseRedirects('/stock?page=1');
        $this->client->followRedirect();

        self::assertSame(1, $this->lineCount());
        self::assertSame(1, $this->quantity('Tortank'));
        self::assertSelectorTextContains('.alert-success', '2 lignes supprimées');
    }

    public function testDeletingWithoutSelectionIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/stock');
        $this->client->submit($crawler->selectButton('Supprimer la sélection')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-warning', 'Sélectionnez au moins une carte.');
        self::assertSame(3, $this->lineCount());
    }

    public function testAnotherUsersLinesCannotBeChangedOrDeleted(): void
    {
        $bob = $this->createUser('bob@example.com');
        $bobLine = $this->addLine($bob, 'Mew', 4);
        $crawler = $this->client->request('GET', '/stock');
        $token = $crawler->filter('#stock-selection input[name="stock_deletion[_token]"]')->attr('value');

        $this->client->request('POST', '/stock/'.$bobLine->getId().'/quantity', ['line_'.$bobLine->getId() => ['previous' => 4, 'quantity' => 0, 'apply' => '']]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/stock/delete', ['stock_deletion' => ['ids' => [$bobLine->getId()], '_token' => $token, 'confirm' => '']]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-warning', 'n\'existent plus');
        self::assertSame(4, $this->em->getConnection()->fetchOne('SELECT quantity FROM stock_item WHERE id = ?', [$bobLine->getId()]));
    }

    public function testTheOverviewCountsTheUsersCardsByEveryDimension(): void
    {
        $this->seedMoreLines();
        $this->addLine($this->createUser('bob@example.com'), 'Mew', 50, 'en', 'jungle', 'Rare', 'Pokemon');

        $crawler = $this->client->request('GET', '/stock');

        self::assertSame('13 cartes 5 références 2 extensions 2 langues', preg_replace('/\s+/', ' ', trim($crawler->filter('[data-overview]')->text())));
        self::assertEquals(['FR' => 8, 'EN' => 5], $this->breakdown($crawler, 'language'));
        self::assertEquals(['Extension base1' => 6, 'Extension jungle' => 7], $this->breakdown($crawler, 'extension'));
        self::assertEquals(['Sans rareté' => 6, 'Common' => 5, 'Rare' => 2], $this->breakdown($crawler, 'rarity'));
        self::assertEquals(['Non renseignée' => 6, 'Pokemon' => 5, 'Trainer' => 2], $this->breakdown($crawler, 'category'));
        // The most numerous value comes first and links to the matching filter.
        self::assertSame('FR', trim($crawler->filter('[data-breakdown="language"] li a')->first()->text()));
        self::assertStringContainsString('language=en', $crawler->filter('[data-breakdown="language"] li a')->eq(1)->attr('href'));
    }

    /**
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('filters')]
    public function testFilters(string $query, array $expected): void
    {
        $this->seedMoreLines();

        $crawler = $this->client->request('GET', '/stock?'.$query);

        self::assertResponseIsSuccessful();
        $names = $crawler->filter('.card-body .fw-semibold')->each(static fn (Crawler $name) => trim($name->text()));
        sort($names);
        self::assertSame($expected, $names);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function filters(): iterable
    {
        yield 'name, accent and case insensitive' => ['q=DRACAU', ['Dracaufeu']];
        yield 'language' => ['language=en', ['Pikachu']];
        yield 'extension by id' => ['extension=jungle', ['Dresseur', 'Pikachu']];
        yield 'number' => ['number=4', ['Pikachu']];
        yield 'rarity' => ['rarity=Rare', ['Dresseur']];
        yield 'category' => ['category=Trainer', ['Dresseur']];
        yield 'minimum quantity' => ['minQuantity=3', ['Alakazam', 'Pikachu']];
        yield 'maximum quantity' => ['maxQuantity=1', ['Tortank']];
        yield 'combined' => ['language=fr&minQuantity=2&maxQuantity=3', ['Alakazam', 'Dracaufeu', 'Dresseur']];
        yield 'unknown language falls back to the whole stock' => ['language=zz', ['Alakazam', 'Dracaufeu', 'Dresseur', 'Pikachu', 'Tortank']];
        yield 'invalid quantity falls back to the whole stock' => ['minQuantity=-2', ['Alakazam', 'Dracaufeu', 'Dresseur', 'Pikachu', 'Tortank']];
        yield 'no match' => ['q=zzz', []];
    }

    public function testTheResultCountAppearsOnlyWhenFiltering(): void
    {
        $this->seedMoreLines();

        $crawler = $this->client->request('GET', '/stock');
        self::assertCount(0, $crawler->filter('[data-result-count]'));

        $crawler = $this->client->request('GET', '/stock?extension=jungle');
        self::assertSame('2 références (7 cartes) correspondent à ces critères.', preg_replace('/\s+/', ' ', trim($crawler->filter('[data-result-count]')->text())));
    }

    public function testNoMatchIsNotConfusedWithAnEmptyStock(): void
    {
        $this->client->request('GET', '/stock?q=zzz');

        self::assertSelectorTextContains('.alert-info', 'Aucune carte de votre stock ne correspond');
        self::assertSelectorTextNotContains('body', 'Votre stock est vide');
        self::assertSelectorExists('[data-overview]');
    }

    public function testAnInvalidFilterIsIgnoredWithAWarning(): void
    {
        $this->client->request('GET', '/stock?language=zz');

        self::assertSelectorTextContains('.alert-warning', 'critère de recherche invalide');
    }

    public function testEditingALineKeepsTheFilters(): void
    {
        $this->seedMoreLines();
        $crawler = $this->client->request('GET', '/stock?language=en');

        $this->press($crawler, 'Pikachu', 'more');
        self::assertResponseRedirects('/stock?language=en&page=1');
        self::assertSame(6, $this->quantity('Pikachu'));

        $this->press($this->client->request('GET', '/stock?language=en'), 'Pikachu', 'apply', 0);
        self::assertSelectorTextContains('h1', 'Retirer cette carte');
        self::assertStringContainsString('language=en', $this->client->getCrawler()->selectButton('Confirmer la suppression')->form()->getUri());
        self::assertStringContainsString('language=en', $this->client->getCrawler()->selectLink('Annuler')->attr('href'));
    }

    public function testRequestsFromAnotherSiteAreRefused(): void
    {
        $crawler = $this->client->request('GET', '/stock');
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'cross-site');

        $this->press($crawler, 'Alakazam', 'more');

        self::assertSame(3, $this->quantity('Alakazam'));
    }

    public function testAnonymousVisitorsCannotEditTheStock(): void
    {
        static::ensureKernelShutdown();
        $anonymous = static::createClient();

        $anonymous->request('POST', '/stock/'.$this->lines['Alakazam']->getId().'/quantity');
        self::assertResponseRedirects('/login');
        $anonymous->request('POST', '/stock/delete');
        self::assertResponseRedirects('/login');
    }

    private function seedMoreLines(): void
    {
        $this->lines['Pikachu'] = $this->addLine($this->user, 'Pikachu', 5, 'en', 'jungle', 'Common', 'Pokemon');
        $this->lines['Dresseur'] = $this->addLine($this->user, 'Dresseur', 2, 'fr', 'jungle', 'Rare', 'Trainer');
    }

    /** @return array<string, int> copies per label of one overview block */
    private function breakdown(Crawler $crawler, string $param): array
    {
        $rows = [];
        foreach ($crawler->filter(\sprintf('[data-breakdown="%s"] li', $param)) as $node) {
            $row = new Crawler($node);
            $rows[trim($row->filter('a, span')->first()->text())] = (int) $row->filter('.badge')->text();
        }

        return $rows;
    }

    private function press(Crawler $crawler, string $line, string $button, ?int $typed = null): void
    {
        $id = $this->lines[$line]->getId();
        $values = null === $typed ? [] : [\sprintf('line_%d[quantity]', $id) => $typed];

        $this->client->submit($crawler->filter(\sprintf('button[name="line_%d[%s]"]', $id, $button))->form($values));
    }

    private function quantity(string $line): ?int
    {
        $quantity = $this->em->getConnection()->fetchOne('SELECT quantity FROM stock_item WHERE id = ?', [$this->lines[$line]->getId()]);

        return false === $quantity ? null : (int) $quantity;
    }

    private function lineCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM stock_item WHERE user_id = ?', [$this->user->getId()]);
    }

    private function addLine(User $user, string $name, int $quantity, string $language = 'fr', string $setId = 'base1', ?string $rarity = null, ?string $category = null): StockItem
    {
        $set = $this->em->getRepository(CardSet::class)->findOneBy(['tcgdexId' => $setId, 'language' => $language]);
        if (null === $set) {
            $set = new CardSet($setId, $language);
            $set->refreshFrom(['name' => 'Extension '.$setId]);
            $this->em->persist($set);
        }

        $number = ++$this->cardCount;
        $card = new Card($setId.'-'.$number, $language, $set);
        $card->refreshFrom(['localId' => (string) $number, 'name' => $name, 'rarity' => $rarity, 'category' => $category], $set, new \DateTimeImmutable());
        $line = new StockItem($user, $card, $quantity);
        $this->em->persist($card);
        $this->em->persist($line);
        $this->em->flush();

        return $line;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setIsVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
