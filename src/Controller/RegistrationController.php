<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\EmailRequestFormType;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailSendingLimiter;
use App\Security\EmailVerifier;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        private readonly EmailSendingLimiter $emailLimiter,
    ) {
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserRepository $users, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_stock');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->emailLimiter->consume($request);

            // The response is identical whether or not the address is already taken, so accounts cannot be enumerated.
            $existing = $users->findOneByEmail((string) $user->getEmail());

            if (null !== $existing) {
                $this->emailVerifier->sendAlreadyRegistered($existing);
            } else {
                $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));

                try {
                    $entityManager->persist($user);
                    $entityManager->flush();
                    $this->emailVerifier->sendEmailConfirmation($user);
                } catch (UniqueConstraintViolationException) {
                    // Concurrent registration with the same address: nothing more to do.
                }
            }

            return $this->redirectToRoute('app_register_check_email');
        }

        return $this->render('registration/register.html.twig', ['registrationForm' => $form]);
    }

    #[Route('/register/check-email', name: 'app_register_check_email')]
    public function checkEmail(): Response
    {
        return $this->render('registration/check_email.html.twig');
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, UserRepository $users): Response
    {
        $id = $request->query->get('id');
        $user = \is_string($id) && ctype_digit($id) ? $users->find((int) $id) : null;

        if (!$user instanceof User) {
            $this->addFlash('danger', 'Le lien de confirmation est invalide ou a expiré.');

            return $this->redirectToRoute('app_verify_email_resend');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', 'Votre adresse email est déjà confirmée, vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface) {
            $this->addFlash('danger', 'Le lien de confirmation est invalide ou a expiré. Demandez un nouvel email de confirmation.');

            return $this->redirectToRoute('app_verify_email_resend');
        }

        $this->addFlash('success', 'Votre adresse email est confirmée, vous pouvez maintenant vous connecter.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/verify/resend', name: 'app_verify_email_resend')]
    public function resend(Request $request, UserRepository $users): Response
    {
        $form = $this->createForm(EmailRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->emailLimiter->consume($request);

            $user = $users->findOneByEmail((string) $form->get('email')->getData());

            if ($user instanceof User && !$user->isVerified()) {
                $this->emailVerifier->sendEmailConfirmation($user);
            }

            $this->addFlash('info', 'Si un compte non confirmé existe pour cette adresse, un nouvel email de confirmation vient d\'être envoyé.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/resend.html.twig', ['form' => $form]);
    }
}
