<?php

namespace App\Controller;

use App\Catalog\CardImageResponder;
use App\Entity\Card;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CardImageController extends AbstractController
{
    #[Route('/cards/{id}/image', name: 'card_image', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function image(Card $card, CardImageResponder $images): Response
    {
        return $images->response($card);
    }
}
