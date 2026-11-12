<?php

namespace App\Catalog;

use App\Entity\Card;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Catalogue images live outside the public directory: controllers serve them through this. */
final class CardImageResponder
{
    public function __construct(
        #[Autowire('%app.catalog_images_dir%')]
        private readonly string $imagesDir,
    ) {
    }

    public function response(Card $card): BinaryFileResponse
    {
        $root = realpath($this->imagesDir);
        $path = null !== $card->getImagePath() && false !== $root ? realpath($root.'/'.$card->getImagePath()) : false;

        if (false === $path || !str_starts_with($path, $root.\DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new NotFoundHttpException('No image for this card.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/webp');
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }
}
