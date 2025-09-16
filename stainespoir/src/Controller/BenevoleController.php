<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BenevoleController extends AbstractController
{
    #[Route('/comment-devenir-benevole', name: 'app_benevole')]
    public function index(): Response
    {
        return $this->render('benevole/index.html.twig', [
            'controller_name' => 'BenevoleController',
        ]);
    }
}
