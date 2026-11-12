<?php

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PromoteAdminCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE reset_password_request, app_user RESTART IDENTITY CASCADE');

        $this->tester = new CommandTester((new Application($kernel))->find('app:user:promote-admin'));
    }

    public function testGrantsTheAdministratorRole(): void
    {
        $this->createUser('alice@example.com');

        $status = $this->tester->execute(['email' => 'alice@example.com']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertContains('ROLE_ADMIN', $this->roles('alice@example.com'));
        self::assertContains('ROLE_USER', $this->roles('alice@example.com'));
    }

    public function testEmailIsMatchedCaseInsensitively(): void
    {
        $this->createUser('alice@example.com');

        self::assertSame(Command::SUCCESS, $this->tester->execute(['email' => ' Alice@Example.COM ']));
        self::assertContains('ROLE_ADMIN', $this->roles('alice@example.com'));
    }

    public function testGrantingTwiceChangesNothing(): void
    {
        $this->createUser('alice@example.com');
        $this->tester->execute(['email' => 'alice@example.com']);

        $status = $this->tester->execute(['email' => 'alice@example.com']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('already has', $this->tester->getDisplay());
        self::assertSame(1, array_count_values($this->roles('alice@example.com'))['ROLE_ADMIN']);
    }

    public function testUnknownEmailFailsAndCreatesNoAccount(): void
    {
        $status = $this->tester->execute(['email' => 'nobody@example.com']);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(0, $this->em->getRepository(User::class)->count([]));
    }

    public function testRevokeRemovesTheRoleButKeepsTheAccount(): void
    {
        $this->createUser('alice@example.com', ['ROLE_ADMIN']);

        $status = $this->tester->execute(['email' => 'alice@example.com', '--revoke' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertNotContains('ROLE_ADMIN', $this->roles('alice@example.com'));
        self::assertContains('ROLE_USER', $this->roles('alice@example.com'));
    }

    public function testRevokingANonAdministratorChangesNothing(): void
    {
        $this->createUser('alice@example.com');

        $status = $this->tester->execute(['email' => 'alice@example.com', '--revoke' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('does not have', $this->tester->getDisplay());
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = []): void
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setIsVerified(true)->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    /** @return list<string> */
    private function roles(string $email): array
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email])->getRoles();
    }
}
