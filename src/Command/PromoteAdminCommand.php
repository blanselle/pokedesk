<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:promote-admin', description: 'Grants (or with --revoke, removes) the administrator role of an existing user')]
final class PromoteAdminCommand
{
    private const ROLE = 'ROLE_ADMIN';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Email address of an existing account')]
        string $email,
        #[Option(description: 'Remove the administrator role instead of granting it')]
        bool $revoke = false,
    ): int {
        $user = $this->users->findOneByEmail($email);

        if (null === $user) {
            $io->error(\sprintf('No account found for "%s". Nothing was created.', $email));

            return Command::FAILURE;
        }

        $roles = $user->getRoles();
        $isAdmin = \in_array(self::ROLE, $roles, true);

        if ($revoke === !$isAdmin) {
            $io->note(\sprintf('%s %s the administrator role: nothing to do.', $user->getEmail(), $revoke ? 'does not have' : 'already has'));

            return Command::SUCCESS;
        }

        $user->setRoles($revoke
            ? array_values(array_diff($roles, [self::ROLE]))
            : [...$roles, self::ROLE]);
        $this->entityManager->flush();

        $io->success(\sprintf('%s %s the administrator role.', $user->getEmail(), $revoke ? 'no longer has' : 'now has'));

        return Command::SUCCESS;
    }
}
