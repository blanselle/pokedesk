<?php

namespace App\Controller;

use App\Entity\StockItem;
use App\Entity\User;
use App\Form\StockDeletionType;
use App\Form\StockFilterType;
use App\Form\StockQuantityType;
use App\Repository\StockItemRepository;
use App\Stock\QuantityChange;
use App\Stock\StockCriteria;
use App\Stock\StockDeletion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class StockController extends AbstractController
{
    private const PER_PAGE = 24;
    private const FILTER_KEYS = ['q', 'language', 'extension', 'number', 'rarity', 'category', 'minQuantity', 'maxQuantity'];

    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->redirectToRoute('app_stock');
    }

    #[Route('/stock', name: 'app_stock')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request, StockItemRepository $stock, FormFactoryInterface $forms, #[CurrentUser] User $user): Response
    {
        $breakdowns = $stock->breakdowns($user);
        $options = [
            'languages' => $this->keys($breakdowns['language']),
            'rarities' => $this->keys($breakdowns['rarity']),
            'categories' => $this->keys($breakdowns['category']),
        ];

        $criteria = new StockCriteria();
        $form = $this->createForm(StockFilterType::class, $criteria, $options);
        $form->handleRequest($request);

        // An invalid value in the URL (e.g. a language absent from the stock) is ignored rather than failing the page.
        if ($form->isSubmitted() && !$form->isValid()) {
            $criteria = new StockCriteria();
            $form = $this->createForm(StockFilterType::class, $criteria, $options);
            $this->addFlash('warning', 'Un critère de recherche invalide a été ignoré.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $items = $stock->findPageForUser($user, $criteria, $page, self::PER_PAGE);
        $pageCount = max(1, (int) ceil(\count($items) / self::PER_PAGE));

        if ($page > $pageCount) {
            $page = $pageCount;
            $items = $stock->findPageForUser($user, $criteria, $page, self::PER_PAGE);
        }

        $filters = $this->filters($request);
        $query = $filters + ['page' => $page];
        $filtered = !$criteria->isEmpty();

        // One form per line, named after it so that field ids stay unique on the page.
        $quantityForms = [];
        foreach ($items as $item) {
            $quantityForms[$item->getId()] = $forms->createNamed('line_'.$item->getId(), StockQuantityType::class, new QuantityChange($item->getQuantity(), $item->getQuantity()), [
                'action' => $this->generateUrl('app_stock_quantity', ['id' => $item->getId()] + $query),
            ])->createView();
        }

        $stockCopies = array_sum(array_column($breakdowns['language'], 'copies'));

        return $this->render('stock/index.html.twig', [
            'items' => $items,
            'lines' => \count($items),
            'copies' => $filtered ? $stock->totalCopies($user, $criteria) : $stockCopies,
            'filtered' => $filtered,
            'filters' => $filters,
            'form' => $form,
            'breakdowns' => $breakdowns,
            'stock_copies' => $stockCopies,
            'stock_lines' => array_sum(array_column($breakdowns['language'], 'lines')),
            'page' => $page,
            'page_count' => $pageCount,
            'quantity_forms' => $quantityForms,
            'selection' => $this->createForm(StockDeletionType::class, null, ['action' => $this->generateUrl('app_stock_delete', $query)])->createView(),
        ]);
    }

    #[Route('/stock/{id}/quantity', name: 'app_stock_quantity', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function quantity(int $id, Request $request, StockItemRepository $stock, FormFactoryInterface $forms, #[CurrentUser] User $user): Response
    {
        $item = $stock->findOneBy(['id' => $id, 'user' => $user]) ?? throw $this->createNotFoundException();

        $change = new QuantityChange();
        $form = $forms->createNamed('line_'.$id, StockQuantityType::class, $change);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('warning', \sprintf('Quantité invalide : indiquez un nombre entier entre 0 et %d.', QuantityChange::MAX));

            return $this->backToList($request);
        }

        $target = match (true) {
            $form->get('less')->isClicked() => $change->previous - 1,
            $form->get('more')->isClicked() => $change->previous + 1,
            default => $change->quantity,
        };

        if ($target > QuantityChange::MAX) {
            $this->addFlash('warning', \sprintf('La quantité ne peut pas dépasser %d.', QuantityChange::MAX));

            return $this->backToList($request);
        }

        if ($target < 1) {
            return $this->confirmDeletion([$item], $request);
        }

        if ($target !== $change->previous && !$stock->adjustQuantity($user, $id, $target - $change->previous)) {
            $this->addFlash('warning', 'Cette ligne vient d\'être modifiée : vérifiez la quantité affichée.');
        }

        return $this->backToList($request);
    }

    #[Route('/stock/delete', name: 'app_stock_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Request $request, StockItemRepository $stock, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(StockDeletionType::class, new StockDeletion());
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('warning', $form->getErrors(true)->current()?->getMessage() ?? 'Sélectionnez au moins une carte.');

            return $this->backToList($request);
        }

        $lines = $stock->findOwned($user, array_values($form->getData()->ids));
        if ([] === $lines) {
            $this->addFlash('warning', 'Les lignes sélectionnées n\'existent plus dans votre stock.');

            return $this->backToList($request);
        }

        if (!$form->get('confirm')->isClicked()) {
            return $this->confirmDeletion($lines, $request);
        }

        $count = $stock->deleteOwned($user, array_map(static fn (StockItem $line) => $line->getId(), $lines));
        $this->addFlash('success', 1 === $count ? '1 ligne supprimée de votre stock.' : \sprintf('%d lignes supprimées de votre stock.', $count));

        return $this->backToList($request);
    }

    /** @param list<StockItem> $lines */
    private function confirmDeletion(array $lines, Request $request): Response
    {
        $query = $this->query($request);
        $form = $this->createForm(StockDeletionType::class, new StockDeletion(), [
            'action' => $this->generateUrl('app_stock_delete', $query),
        ]);

        return $this->render('stock/confirm_delete.html.twig', [
            'form' => $form,
            'lines' => $lines,
            'copies' => array_sum(array_map(static fn (StockItem $line) => $line->getQuantity(), $lines)),
            'query' => $query,
        ]);
    }

    private function backToList(Request $request): Response
    {
        return $this->redirectToRoute('app_stock', $this->query($request));
    }

    /** The filters and page of the list the user came from, so that they get back to the same one. */
    private function query(Request $request): array
    {
        return $this->filters($request) + ['page' => max(1, $request->query->getInt('page', 1))];
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $all = $request->query->all();
        $filters = [];
        foreach (self::FILTER_KEYS as $key) {
            if (isset($all[$key]) && \is_string($all[$key]) && '' !== $all[$key]) {
                $filters[$key] = $all[$key];
            }
        }

        return $filters;
    }

    /**
     * @param list<array{key: string|null}> $rows
     *
     * @return list<string>
     */
    private function keys(array $rows): array
    {
        return array_values(array_filter(array_column($rows, 'key'), static fn (?string $key) => null !== $key));
    }
}
