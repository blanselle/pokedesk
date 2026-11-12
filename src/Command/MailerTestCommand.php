<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeExceptionInterface;

#[AsCommand(name: 'app:mailer:test', description: 'Sends a test email to check the mailer configuration')]
final class MailerTestCommand
{
    public function __construct(private readonly MailerInterface $mailer)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Recipient email address')]
        string $to,
        #[Option(description: 'Sender email address')]
        string $from = 'noreply@app.local',
    ): int {
        try {
            $this->mailer->send(
                (new Email())
                    ->from($from)
                    ->to($to)
                    ->subject('Test email')
                    ->text('If you read this, the mailer is working.')
            );
        } catch (MimeExceptionInterface $e) {
            $io->error(\sprintf('Invalid email address: %s', $e->getMessage()));

            return Command::INVALID;
        } catch (TransportExceptionInterface $e) {
            $io->error(\sprintf('Sending failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Test email sent to %s.', $to));

        return Command::SUCCESS;
    }
}
