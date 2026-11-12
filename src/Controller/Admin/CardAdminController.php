<?php

namespace App\Controller\Admin;

use App\Admin\CardFilter;
use App\Entity\Card;
use App\Form\CardFilterType;
use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/cards')]
#[IsGranted('ROLE_ADMIN')]
final class CardAdminController extends AbstractController
{
    private const PER_PAGE_DEFAULT = 50;
    private const PER_PAGE_MAX = 100;
    private const VIEWS = ['table', 'grid'];
    private const VIEW_SESSION_KEY = 'admin_cards_view';

    #[Route('', name: 'admin_cards')]
    public function index(Request $request, CardRepository $cards): Response
    {
        $filter = new CardFilter();
        $options = [
            'languages' => $cards->distinctLanguages(),
            'rarities' => $cards->distinctRarities(),
        ];
        $form = $this->createForm(CardFilterType::class, $filter, $options);
        $form->handleRequest($request);

        // An invalid value in the URL (e.g. a language with no card) is ignored rather than failing the page.
        if ($form->isSubmitted() && !$form->isValid()) {
            $filter = new CardFilter();
            $form = $this->createForm(CardFilterType::class, $filter, $options);
            $this->addFlash('warning', 'Un critère de recherche invalide a été ignoré.');
        }

        $perPage = max(1, min(self::PER_PAGE_MAX, $request->query->getInt('per_page', self::PER_PAGE_DEFAULT)));
        $page = max(1, $request->query->getInt('page', 1));

        // The last explicit choice is kept for the rest of the session.
        $session = $request->getSession();
        $view = $request->query->get('view');
        if (\in_array($view, self::VIEWS, true)) {
            $session->set(self::VIEW_SESSION_KEY, $view);
        } else {
            $view = $session->get(self::VIEW_SESSION_KEY, 'table');
        }

        $paginator = $cards->search($filter, $page, $perPage);
        $total = \count($paginator);
        $pageCount = max(1, (int) ceil($total / $perPage));

        if ($page > $pageCount) {
            $page = $pageCount;
            $paginator = $cards->search($filter, $page, $perPage);
        }

        return $this->render('admin/card/index.html.twig', [
            'form' => $form,
            'cards' => $paginator,
            'total' => $total,
            'page' => $page,
            'page_count' => $pageCount,
            'per_page' => $perPage,
            'view' => $view,
            'per_page_choices' => [25, self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX],
        ]);
    }

    #[Route('/{id}', name: 'admin_card_show', requirements: ['id' => '\d+'])]
    public function show(Card $card, CardRepository $cards): Response
    {
        return $this->render('admin/card/show.html.twig', [
            'card' => $card,
            'other_languages' => $cards->findOtherLanguages($card),
        ]);
    }

    // Images live outside the public directory: they are served here, to administrators only.
    #[Route('/{id}/image', name: 'admin_card_image', requirements: ['id' => '\d+'])]
    public function image(Card $card, #[Autowire('%app.catalog_images_dir%')] string $imagesDir): Response
    {
        $root = realpath($imagesDir);
        $path = null !== $card->getImagePath() && false !== $root ? realpath($root.'/'.$card->getImagePath()) : false;

        if (false === $path || !str_starts_with($path, $root.\DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw $this->createNotFoundException('No image for this card.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/webp');
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }
}
