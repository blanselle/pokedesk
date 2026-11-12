<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthenticationTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Stateless CSRF validation relies on origin headers, which the test browser does not send.
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE reset_password_request, app_user RESTART IDENTITY CASCADE');
    }

    public function testRegistrationCreatesAnUnverifiedUserAndSendsAConfirmationEmail(): void
    {
        $this->register('Alice@Example.com', self::PASSWORD);

        self::assertResponseRedirects('/register/check-email');
        self::assertEmailCount(1);

        $user = $this->findUser('alice@example.com');
        self::assertNotNull($user);
        self::assertFalse($user->isVerified());
        self::assertNotSame(self::PASSWORD, $user->getPassword());

        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'to', 'alice@example.com');
        self::assertStringContainsString('/verify/email', $this->htmlBody($email));
    }

    public function testRegistrationRejectsAShortPassword(): void
    {
        $this->register('bob@example.com', 'too-short');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findUser('bob@example.com'));
        self::assertEmailCount(0);
    }

    public function testRegistrationRejectsMismatchedPasswords(): void
    {
        $this->register('bob@example.com', self::PASSWORD, 'another-password-1');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findUser('bob@example.com'));
    }

    public function testRegisteringAnExistingEmailDoesNotRevealItAndCreatesNoSecondAccount(): void
    {
        $this->createUser('alice@example.com', verified: true);

        $this->register('alice@example.com', 'another-long-password');

        self::assertResponseRedirects('/register/check-email');
        self::assertSame(1, $this->em->getRepository(User::class)->count([]));
        self::assertEmailCount(1);
        self::assertStringContainsString('existe déjà', (string) self::getMailerMessage()->getSubject());
    }

    public function testUnverifiedUserCannotLogIn(): void
    {
        $this->createUser('alice@example.com', verified: false);

        $this->login('alice@example.com', self::PASSWORD);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'pas encore été confirmée');

        $this->client->request('GET', '/stock');
        self::assertResponseRedirects('/login');
    }

    public function testEmailVerificationLinkActivatesTheAccountAndAllowsLogin(): void
    {
        $this->register('alice@example.com', self::PASSWORD);
        $link = $this->extractLink(self::getMailerMessage(), '/verify/email');

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/login');
        self::assertTrue($this->findUser('alice@example.com')->isVerified());

        $this->login('alice@example.com', self::PASSWORD);
        self::assertResponseRedirects('/stock');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mon stock');
    }

    public function testTamperedVerificationLinkIsRejected(): void
    {
        $this->register('alice@example.com', self::PASSWORD);
        $link = $this->extractLink(self::getMailerMessage(), '/verify/email');

        $this->client->request('GET', preg_replace('/signature=[^&]+/', 'signature=tampered', $link));

        self::assertResponseRedirects('/verify/resend');
        self::assertFalse($this->findUser('alice@example.com')->isVerified());
    }

    public function testVerificationEmailCanBeRequestedAgain(): void
    {
        $this->createUser('alice@example.com', verified: false);

        $this->client->request('GET', '/verify/resend');
        $this->client->submitForm('Envoyer', ['email_request_form[email]' => 'alice@example.com']);

        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
    }

    public function testVerificationResendDoesNotRevealUnknownOrVerifiedAccounts(): void
    {
        $this->createUser('alice@example.com', verified: true);

        foreach (['alice@example.com', 'nobody@example.com'] as $address) {
            $this->client->request('GET', '/verify/resend');
            $this->client->submitForm('Envoyer', ['email_request_form[email]' => $address]);
            self::assertResponseRedirects('/login');
        }

        self::assertEmailCount(0);
    }

    public function testLoginWithWrongPasswordShowsAnError(): void
    {
        $this->createUser('alice@example.com', verified: true);

        $this->login('alice@example.com', 'wrong-password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
    }

    public function testStockRequiresAuthentication(): void
    {
        $this->client->request('GET', '/stock');

        self::assertResponseRedirects('/login');
    }

    public function testLogout(): void
    {
        $this->createUser('alice@example.com', verified: true);
        $this->login('alice@example.com', self::PASSWORD);
        $crawler = $this->client->followRedirect();

        $this->client->click($crawler->selectLink('Se déconnecter')->link());
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/stock');
        self::assertResponseRedirects('/login');
    }

    public function testPasswordResetFlow(): void
    {
        $this->createUser('alice@example.com', verified: true);

        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Envoyer le lien', ['email_request_form[email]' => 'alice@example.com']);
        self::assertResponseRedirects('/reset-password/check-email');
        self::assertEmailCount(1);

        $link = $this->extractLink(self::getMailerMessage(), '/reset-password/reset/');
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/reset-password/reset');
        $this->client->followRedirect();

        $this->client->submitForm('Modifier le mot de passe', [
            'change_password_form[plainPassword][first]' => 'a-brand-new-password',
            'change_password_form[plainPassword][second]' => 'a-brand-new-password',
        ]);
        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
        self::assertStringContainsString('modifié', (string) self::getMailerMessage()->getSubject());

        $this->login('alice@example.com', self::PASSWORD);
        self::assertResponseRedirects('/login');

        $this->login('alice@example.com', 'a-brand-new-password');
        self::assertResponseRedirects('/stock');

        // The link is single-use.
        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password');
    }

    public function testPasswordResetForUnknownEmailRevealsNothing(): void
    {
        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Envoyer le lien', ['email_request_form[email]' => 'nobody@example.com']);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertEmailCount(0);
    }

    public function testPasswordResetConfirmsTheEmailAddressOfAnUnverifiedAccount(): void
    {
        $this->createUser('alice@example.com', verified: false);

        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Envoyer le lien', ['email_request_form[email]' => 'alice@example.com']);
        $link = $this->extractLink(self::getMailerMessage(), '/reset-password/reset/');

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        $this->client->submitForm('Modifier le mot de passe', [
            'change_password_form[plainPassword][first]' => 'a-brand-new-password',
            'change_password_form[plainPassword][second]' => 'a-brand-new-password',
        ]);

        $this->em->clear();
        self::assertTrue($this->findUser('alice@example.com')->isVerified());
    }

    private function register(string $email, string $password, ?string $confirmation = null): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Créer mon compte', [
            'registration_form[email]' => $email,
            'registration_form[plainPassword][first]' => $password,
            'registration_form[plainPassword][second]' => $confirmation ?? $password,
        ]);
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => $email, '_password' => $password]);
    }

    private function createUser(string $email, bool $verified): User
    {
        $user = (new User())->setEmail($email)->setIsVerified($verified);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function findUser(string $email): ?User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function htmlBody(Email $email): string
    {
        return (string) $email->getHtmlBody();
    }

    private function extractLink(Email $email, string $needle): string
    {
        self::assertSame(1, preg_match('#href="([^"]*'.preg_quote($needle, '#').'[^"]*)"#', $this->htmlBody($email), $matches));

        return html_entity_decode($matches[1]);
    }
}
