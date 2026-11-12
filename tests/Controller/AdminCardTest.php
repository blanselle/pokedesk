<?php

namespace App\Tests\Controller;

use App\Entity\Card;
use App\Entity\CardSet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

final class AdminCardTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $imagesDir;

    /** @var array<string, CardSet> */
    private array $sets = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE card, card_set, reset_password_request, app_user RESTART IDENTITY CASCADE');
        $this->imagesDir = static::getContainer()->getParameter('app.catalog_images_dir');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(\dirname($this->imagesDir));
        parent::tearDown();
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        $this->client->request('GET', '/admin/cards');

        self::assertResponseRedirects('/login');
    }

    public function testRegularUsersAreDenied(): void
    {
        $this->client->loginUser($this->createUser('user@example.com'));

        foreach (['/admin/cards', '/admin/cards/1', '/admin/cards/1/image'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testAdministratorsSeeTheListAndTheNavigationLink(): void
    {
        $this->seedCatalogue();
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/admin/cards');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Cartes importées');
        self::assertSelectorExists('a.nav-link[href="/admin/cards"]');
        self::assertCount(5, $crawler->filter('tbody tr'));
        // Ordered by language, set then number.
        self::assertSame(['Pikachu', 'Alakazam', 'Magneton', 'Pikachu', 'Évoli'], $this->names($crawler));
    }

    public function testTheNavigationLinkIsHiddenFromRegularUsers(): void
    {
        $this->client->loginUser($this->createUser('user@example.com'));

        $this->client->request('GET', '/stock');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/admin/cards"]');
    }

    public function testPaginationDefaultsToFiftyAndIsCappedAtOneHundred(): void
    {
        $this->seedManyCards(120);
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $expected = [
            '/admin/cards' => 50,
            '/admin/cards?page=3' => 20,
            '/admin/cards?per_page=100' => 100,
            '/admin/cards?per_page=500' => 100,
            '/admin/cards?per_page=10' => 10,
            '/admin/cards?per_page=-4' => 1,
            '/admin/cards?page=999' => 20,
        ];

        foreach ($expected as $url => $rows) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertCount($rows, $crawler->filter('tbody tr'), $url);
        }
    }

    public function testPaginationLinksKeepTheFilters(): void
    {
        $this->seedManyCards(120);
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/admin/cards?language=fr&per_page=25');

        $next = $crawler->filter('.pagination a.page-link')->reduce(static fn (Crawler $a) => 'Suivant' === trim($a->text()))->attr('href');
        self::assertStringContainsString('language=fr', $next);
        self::assertStringContainsString('per_page=25', $next);
        self::assertStringContainsString('page=2', $next);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('filters')]
    public function testFilters(string $query, array $expected): void
    {
        $this->seedCatalogue();
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/admin/cards?'.$query);

        self::assertResponseIsSuccessful();
        self::assertSame($expected, $this->names($crawler));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function filters(): iterable
    {
        yield 'name, accent and case insensitive' => ['q=evoli', ['Évoli']];
        yield 'name, partial' => ['q=aka', ['Alakazam']];
        yield 'tcgdex id' => ['q=BASE2-51', ['Évoli']];
        yield 'wildcards are not interpreted' => ['q=%25', []];
        yield 'language' => ['language=en', ['Pikachu']];
        yield 'extension by id' => ['extension=base2', ['Évoli']];
        yield 'extension by name' => ['extension=jungle', ['Évoli']];
        yield 'number' => ['number=25', ['Pikachu', 'Pikachu']];
        yield 'rarity' => ['rarity=Rare', ['Alakazam', 'Magneton']];
        yield 'category' => ['category=Trainer', ['Évoli']];
        yield 'obsolete cards' => ['status=obsolete', ['Magneton']];
        yield 'missing image' => ['missingImage=1', ['Magneton', 'Pikachu', 'Évoli']];
        yield 'combined' => ['language=fr&number=25&q=pika', ['Pikachu']];
        yield 'unknown language falls back to the full list' => ['language=zz', ['Pikachu', 'Alakazam', 'Magneton', 'Pikachu', 'Évoli']];
        yield 'no match' => ['q=dracaufeu', []];
    }

    public function testDetailPageShowsMetadataAndPrices(): void
    {
        $this->seedCatalogue();
        $card = $this->findCard('base1-1', 'fr');
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $this->client->request('GET', '/admin/cards/'.$card->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Alakazam');
        self::assertSelectorTextContains('body', 'base1-1');
        self::assertSelectorTextContains('body', 'Set de Base');
        self::assertSelectorTextContains('table', '57.77 EUR');
    }

    public function testDetailPageLinksToTheOtherLanguagesAndBack(): void
    {
        $this->seedCatalogue();
        $french = $this->findCard('base1-25', 'fr');
        $english = $this->findCard('base1-25', 'en');
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/admin/cards/'.$french->getId());
        // The language code is upper-cased by CSS only.
        $link = $crawler->selectLink('en · Pikachu Base Set');
        self::assertCount(1, $link);
        self::assertSame('/admin/cards/'.$english->getId(), $link->attr('href'));

        $crawler = $this->client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('dd.text-uppercase', 'en');

        // Back to the French version, which has no local image.
        $back = $crawler->filter('a.btn-outline-primary');
        self::assertSame('/admin/cards/'.$french->getId(), $back->attr('href'));
        self::assertStringContainsString('Sans image', $back->text());
    }

    public function testDetailPageWithoutOtherLanguageShowsNoLanguageSection(): void
    {
        $this->seedCatalogue();
        $card = $this->findCard('base1-1', 'fr');
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $this->client->request('GET', '/admin/cards/'.$card->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a.btn-outline-primary');
        self::assertSelectorTextNotContains('body', 'Autres langues');
    }

    public function testClickingTheDetailImageOpensItEnlarged(): void
    {
        $this->seedCatalogue();
        $card = $this->findCard('base1-1', 'fr');
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/admin/cards/'.$card->getId());

        $imageUrl = '/admin/cards/'.$card->getId().'/image';
        $trigger = $crawler->filter('button[data-bs-toggle="modal"]');
        self::assertCount(1, $trigger);
        self::assertSame('#card-image-modal', $trigger->attr('data-bs-target'));
        self::assertSame($imageUrl, $trigger->filter('img')->attr('src'));
        self::assertSame($imageUrl, $crawler->filter('#card-image-modal img')->attr('src'));
    }

    public function testDetailPageWithoutImageHasNoModal(): void
    {
        $this->seedCatalogue();
        $card = $this->findCard('base1-9', 'fr');
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $this->client->request('GET', '/admin/cards/'.$card->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#card-image-modal');
        self::assertSelectorTextContains('.alert-warning', 'Aucune image');
    }

    public function testUnknownCardIsNotFound(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $this->client->request('GET', '/admin/cards/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testImageIsServedToAdministratorsOnly(): void
    {
        $this->seedCatalogue();
        (new Filesystem())->dumpFile($this->imagesDir.'/fr/base1/1.webp', 'webp-bytes');
        $card = $this->findCard('base1-1', 'fr');

        $this->client->request('GET', '/admin/cards/'.$card->getId().'/image');
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));
        $this->client->request('GET', '/admin/cards/'.$card->getId().'/image');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'image/webp');
        self::assertSame('webp-bytes', $this->client->getInternalResponse()->getContent());
    }

    public function testImagePathsCannotEscapeTheImagesDirectory(): void
    {
        $this->seedCatalogue();
        (new Filesystem())->mkdir($this->imagesDir);
        (new Filesystem())->dumpFile(\dirname($this->imagesDir).'/secret.txt', 'secret');
        $card = $this->findCard('base1-1', 'fr');
        $card->setImagePath('../secret.txt');
        $this->em->flush();
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $this->client->request('GET', '/admin/cards/'.$card->getId().'/image');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTableIsTheDefaultView(): void
    {
        $this->seedCatalogue();
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/admin/cards');

        self::assertSelectorExists('table');
        self::assertSelectorNotExists('.card-hover');
        self::assertStringContainsString('view=grid', $crawler->selectLink('Cartes')->attr('href'));
    }

    public function testGridViewShowsOneTilePerCard(): void
    {
        $this->seedCatalogue();
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/admin/cards?view=grid');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('table');
        self::assertCount(5, $crawler->filter('.card-hover'));
        self::assertSame(
            ['Pikachu', 'Alakazam', 'Magneton', 'Pikachu', 'Évoli'],
            $crawler->filter('.card-hover .fw-semibold')->each(static fn (Crawler $name) => trim($name->text())),
        );
        // Two cards have a local image, the three others show a placeholder.
        self::assertCount(2, $crawler->filter('.card-hover img'));
        self::assertCount(3, $crawler->filter('.card-hover')->reduce(static fn (Crawler $tile) => str_contains($tile->text(), 'Sans image')));
        self::assertStringContainsString('view=table', $crawler->selectLink('Liste')->attr('href'));
    }

    public function testTheChosenViewIsRemembered(): void
    {
        $this->seedCatalogue();
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $this->client->request('GET', '/admin/cards?view=grid');
        $this->client->request('GET', '/admin/cards?language=fr');
        self::assertSelectorExists('.card-hover');

        $this->client->request('GET', '/admin/cards?view=table');
        $this->client->request('GET', '/admin/cards');
        self::assertSelectorExists('table');
        self::assertSelectorNotExists('.card-hover');
    }

    public function testAnUnknownViewIsIgnored(): void
    {
        $this->seedCatalogue();
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $this->client->request('GET', '/admin/cards?view=evil');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table');
    }

    public function testPaginationLinksKeepTheView(): void
    {
        $this->seedManyCards(120);
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/admin/cards?view=grid');

        $next = $crawler->filter('.pagination a.page-link')->reduce(static fn (Crawler $a) => 'Suivant' === trim($a->text()))->attr('href');
        self::assertStringContainsString('view=grid', $next);
    }

    private function seedCatalogue(): void
    {
        $this->addCard('fr', 'base1', 'Set de Base', 'base1-1', '1', 'Alakazam', 'Rare', 'fr/base1/1.webp', [
            ['type' => 'Holo', 'subtype' => 'illimité', 'pricing' => ['cardmarket' => ['avg' => 57.77, 'low' => 9.99, 'trend' => 74.45, 'unit' => 'EUR', 'updated' => '2026-11-11T23:49:46.167Z']]],
        ]);
        $this->addCard('fr', 'base1', 'Set de Base', 'base1-9', '9', 'Magneton', 'Rare', null);
        $this->addCard('fr', 'base1', 'Set de Base', 'base1-25', '25', 'Pikachu', 'Commune', null);
        $this->addCard('fr', 'base2', 'Jungle', 'base2-51', '51', 'Évoli', 'Commune', null);
        $this->addCard('en', 'base1', 'Base Set', 'base1-25', '25', 'Pikachu', 'Common', 'en/base1/25.webp');
        $this->em->flush();
        $this->em->getConnection()->executeStatement("UPDATE card SET status = 'obsolete' WHERE tcgdex_id = 'base1-9'");
        $this->em->getConnection()->executeStatement("UPDATE card SET category = 'Trainer' WHERE tcgdex_id = 'base2-51'");
        $this->em->clear();
    }

    private function seedManyCards(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->addCard('fr', 'base1', 'Set de Base', 'base1-'.$i, (string) $i, 'Carte '.$i, 'Commune', null);
        }
        $this->em->flush();
        $this->em->clear();
    }

    /** @param list<array<string, mixed>> $variants */
    private function addCard(string $language, string $setId, string $setName, string $id, string $number, string $name, ?string $rarity, ?string $imagePath, array $variants = []): void
    {
        $setKey = $language.'/'.$setId;

        if (!isset($this->sets[$setKey])) {
            $set = new CardSet($setId, $language);
            $set->refreshFrom(['name' => $setName, 'serie' => ['id' => 'base', 'name' => 'Base'], 'cardCount' => ['total' => 102]]);
            $this->em->persist($set);
            $this->sets[$setKey] = $set;
        }

        $card = new Card($id, $language, $this->sets[$setKey]);
        $card->refreshFrom(['localId' => $number, 'name' => $name, 'rarity' => $rarity, 'variants_detailed' => $variants], $this->sets[$setKey], new \DateTimeImmutable());
        $card->setImagePath($imagePath);
        $this->em->persist($card);
    }

    private function findCard(string $id, string $language): Card
    {
        $this->em->clear();

        return $this->em->getRepository(Card::class)->findOneBy(['tcgdexId' => $id, 'language' => $language]);
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setIsVerified(true)->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return list<string> */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr td:nth-child(2)')->each(static fn (Crawler $cell) => trim($cell->text()));
    }
}
