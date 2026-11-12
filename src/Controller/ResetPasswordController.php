<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\EmailRequestFormType;
use App\Repository\UserRepository;
use App\Security\EmailSendingLimiter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/reset-password')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailSendingLimiter $emailLimiter,
    ) {
    }

    #[Route('', name: 'app_forgot_password_request')]
    public function request(Request $request, UserRepository $users, MailerInterface $mailer): Response
    {
        $form = $this->createForm(EmailRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->emailLimiter->consume($request);

            $user = $users->findOneByEmail((string) $form->get('email')->getData());

            // Same response whether or not the account exists, so accounts cannot be enumerated.
            if ($user instanceof User) {
                $this->sendResetEmail($user, $mailer);
            }

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('reset_password/request.html.twig', ['requestForm' => $form]);
    }

    #[Route('/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig', [
            'tokenLifetime' => (int) ($this->resetPasswordHelper->getTokenLifetime() / 60),
        ]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password')]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, MailerInterface $mailer, ?string $token = null): Response
    {
        if ($token) {
            // The token is moved to the session and removed from the URL to keep it out of Referer headers.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();

        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->cleanSessionAfterReset();
            $this->addFlash('danger', 'Ce lien de réinitialisation est invalide ou a expiré. Faites une nouvelle demande.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resetPasswordHelper->removeResetRequest($token);

            $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            // Receiving the reset email proves the address belongs to the user.
            $user->setIsVerified(true);
            $this->entityManager->flush();

            $mailer->send(
                (new TemplatedEmail())
                    ->to((string) $user->getEmail())
                    ->subject('Votre mot de passe a été modifié')
                    ->htmlTemplate('reset_password/changed_email.html.twig')
            );

            $this->cleanSessionAfterReset();
            $this->addFlash('success', 'Votre mot de passe a été modifié, vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', ['resetForm' => $form]);
    }

    private function sendResetEmail(User $user, MailerInterface $mailer): void
    {
        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Typically a throttled request: stay silent to avoid disclosing anything.
            return;
        }

        $mailer->send(
            (new TemplatedEmail())
                ->to((string) $user->getEmail())
                ->subject('Réinitialisation de votre mot de passe')
                ->htmlTemplate('reset_password/email.html.twig')
                ->context([
                    'resetToken' => $resetToken,
                    'lifetimeMinutes' => intdiv($this->resetPasswordHelper->getTokenLifetime(), 60),
                ])
        );
    }
}
