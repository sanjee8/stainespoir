<?php

namespace App\Controller;

use App\Entity\Outing;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class OutingController extends AbstractController
{
    #[Route('/sorties/{id}-{slug}', name: 'outing_show', requirements: ['id' => '\d+'], defaults: ['slug' => null])]
    public function show(Outing $outing, ?string $slug = null): Response
    {
        // Si tu as un champ slug, force l’URL canonique
        if (method_exists($outing, 'getSlug') && $outing->getSlug()) {
            if ($slug !== $outing->getSlug()) {
                return $this->redirectToRoute('outing_show', [
                    'id'   => $outing->getId(),
                    'slug' => $outing->getSlug(),
                ], 301);
            }
        }

        return $this->render('outing/show.html.twig', [
            'o' => $outing,
        ]);
    }
}
