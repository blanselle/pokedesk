<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ScanUploadType;
use App\Repository\CardEmbeddingRepository;
use App\Repository\CardRepository;
use App\Repository\StockItemRepository;
use App\Scan\Candidate;
use App\Scan\ScanService;
use App\Scan\ScanSession;
use App\Scan\ScanStore;
use App\Vision\VisionException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Range;

#[Route('/scan')]
#[IsGranted('ROLE_USER')]
final class ScanController extends AbstractController
{
    private const LANGUAGE_SESSION_KEY = 'scan_language';

    public function __construct(
        private readonly ScanService $scans,
        private readonly ScanStore $store,
        private readonly CardRepository $cards,
        private readonly CardEmbeddingRepository $embeddings,
        private readonly StockItemRepository $stock,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.vision_model%')]
        private readonly string $model,
    ) {
    }

    #[Route('', name: 'app_scan', methods: ['GET', 'POST'])]
    public function upload(Request $request, #[CurrentUser] User $user): Response
    {
        $languages = $this->embeddings->embeddedLanguages($this->model);

        if ([] === $languages) {
            return $this->render('scan/upload.html.twig', ['form' => null]);
        }

        $remembered = $request->getSession()->get(self::LANGUAGE_SESSION_KEY);
        $form = $this->createForm(ScanUploadType::class, ['language' => \in_array($remembered, $languages, true) ? $remembered : $languages[0]], ['languages' => $languages]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $photo */
            $photo = $form->get('photo')->getData();
            $language = (string) $form->get('language')->getData();

            try {
                $session = $this->scans->start($user, $language, $photo->getContent());

                if (null === $session) {
                    $this->addFlash('warning', 'Aucune carte n\'a été détectée sur cette photo. Vérifiez les consignes de prise de vue et réessayez.');
                } else {
                    $request->getSession()->set(self::LANGUAGE_SESSION_KEY, $language);

                    return $this->redirectToNext($session, -1);
                }
            } catch (VisionException $e) {
                $this->logger->error('Scan failed: {message}', ['message' => $e->getMessage()]);
                $this->addFlash('danger', 'Le service de reconnaissance est momentanément indisponible. Réessayez dans un instant.');
            }
        }

        return $this->render('scan/upload.html.twig', ['form' => $form]);
    }

    #[Route('/{token}/card/{position}', name: 'app_scan_card', requirements: ['token' => '[0-9a-f]{32}', 'position' => '\d+'], methods: ['GET', 'POST'])]
    public function card(string $token, int $position, Request $request, #[CurrentUser] User $user): Response
    {
        if (null === $session = $this->store->get($token, $user)) {
            return $this->scanIsGone();
        }

        $scanned = $session->cards[$position] ?? null;
        if (null === $scanned || !$scanned->isReviewable()) {
            throw $this->createNotFoundException();
        }

        $ids = array_map(static fn (Candidate $candidate) => $candidate->cardId, $scanned->candidates);
        $form = $this->createFormBuilder(['candidate' => $scanned->selected()])
            ->add('candidate', ChoiceType::class, [
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choices' => $ids,
                'choice_label' => static fn (int $id) => (string) $id,
                'choice_value' => static fn (?int $id) => null === $id ? '' : (string) $id,
            ])
            ->add('validate', SubmitType::class)
            ->add('none', SubmitType::class)
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $none = $form->get('none')->isClicked();
            $choice = $form->get('candidate')->getData();

            if (!$none && null === $choice) {
                $form->get('candidate')->addError(new FormError('Choisissez une carte, ou indiquez qu\'aucune ne correspond.'));
            } else {
                $scanned->choice = $none ? null : (int) $choice;
                $scanned->decided = true;
                $this->store->save($session);

                return $this->redirectToNext($session, $position);
            }
        }

        $catalogue = $this->cards->findByIdsIndexed($ids);
        $reviewable = $session->reviewable();
        $index = (int) array_search($position, $reviewable, true);

        $candidates = [];
        foreach ($scanned->candidates as $candidate) {
            if (isset($catalogue[$candidate->cardId])) {
                $candidates[$candidate->cardId] = ['card' => $catalogue[$candidate->cardId], 'similarity' => $candidate->similarity];
            }
        }

        return $this->render('scan/card.html.twig', [
            'token' => $token,
            'position' => $position,
            'scanned' => $scanned,
            'form' => $form,
            'candidates' => $candidates,
            'step' => $index + 1,
            'steps' => \count($reviewable),
            'previous' => $reviewable[$index - 1] ?? null,
            'next' => $reviewable[$index + 1] ?? null,
        ]);
    }

    #[Route('/{token}/summary', name: 'app_scan_summary', requirements: ['token' => '[0-9a-f]{32}'], methods: ['GET', 'POST'])]
    public function summary(string $token, Request $request, #[CurrentUser] User $user): Response
    {
        if (null === $session = $this->store->get($token, $user)) {
            return $this->scanIsGone();
        }

        $validated = $session->validated();
        $builder = $this->createFormBuilder();
        foreach ($validated as $cardId => $count) {
            $builder->add('quantity_'.$cardId, IntegerType::class, [
                'label' => false,
                'data' => $count,
                'attr' => ['min' => 0, 'max' => 999],
                'constraints' => [new Range(min: 0, max: 999, notInRangeMessage: 'La quantité doit être comprise entre {{ min }} et {{ max }}.')],
            ]);
        }
        $form = $builder->add('add', SubmitType::class)->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $quantities = [];
            foreach ($validated as $cardId => $_) {
                $quantities[$cardId] = (int) $form->get('quantity_'.$cardId)->getData();
            }

            if (array_sum($quantities) < 1) {
                $this->addFlash('warning', 'Aucune carte à ajouter : toutes les quantités sont à 0.');
            } elseif ($this->commit($session, $user, $quantities)) {
                $this->addFlash('success', \sprintf('%d carte(s) ajoutée(s) à votre stock, pour %d référence(s).', array_sum($quantities), \count(array_filter($quantities))));

                return $this->redirectToRoute('app_stock');
            } else {
                // Another request already ended this scan: never add twice.
                return $this->scanIsGone();
            }
        }

        $catalogue = $this->cards->findByIdsIndexed($session->cardIds());

        return $this->render('scan/summary.html.twig', [
            'token' => $token,
            'form' => $form,
            'validated' => array_map(static fn (int $cardId) => $catalogue[$cardId] ?? null, array_keys($validated)),
            'rejected' => array_map(static fn (int $position) => [
                'position' => $position,
                'reason' => !$session->cards[$position]->isReviewable() ? 'unrecognised' : ($session->cards[$position]->decided ? 'skipped' : 'unchecked'),
            ], $session->notValidated()),
            'total' => \count($session->cards),
        ]);
    }

    #[Route('/{token}/crop/{position}', name: 'app_scan_crop', requirements: ['token' => '[0-9a-f]{32}', 'position' => '\d+'])]
    public function crop(string $token, int $position, #[CurrentUser] User $user): Response
    {
        $session = $this->store->get($token, $user);
        $scanned = $session?->cards[$position] ?? null;

        if (null === $scanned) {
            throw $this->createNotFoundException();
        }

        return new Response($scanned->crop, Response::HTTP_OK, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }

    /** @param array<int, int> $quantities */
    private function commit(ScanSession $session, User $user, array $quantities): bool
    {
        $lock = $this->lockFactory->createLock('scan-commit-'.$session->token);

        if (!$lock->acquire()) {
            return false;
        }

        try {
            // Re-read under the lock: a concurrent request may have finished the scan already.
            if (null === $this->store->get($session->token, $user)) {
                return false;
            }

            $this->stock->addQuantities($user, $quantities);
            $this->store->delete($session);

            return true;
        } finally {
            $lock->release();
        }
    }

    private function redirectToNext(ScanSession $session, int $after): Response
    {
        $reviewable = $session->reviewable();
        $later = array_filter($reviewable, static fn (int $position) => $position > $after && !$session->cards[$position]->decided);
        $next = [] !== $later ? reset($later) : $session->firstUndecided();

        return null === $next
            ? $this->redirectToRoute('app_scan_summary', ['token' => $session->token])
            : $this->redirectToRoute('app_scan_card', ['token' => $session->token, 'position' => $next]);
    }

    private function scanIsGone(): Response
    {
        $this->addFlash('info', 'Ce scan est terminé ou a expiré. Vous pouvez en lancer un nouveau.');

        return $this->redirectToRoute('app_scan');
    }
}
