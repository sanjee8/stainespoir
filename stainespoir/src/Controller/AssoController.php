<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AssoController extends AbstractController
{
    #[Route('/asso', name: 'app_asso')]
    public function index(): Response
    {
        return $this->render('asso/index.html.twig', [
            'controller_name' => 'AssoController',
        ]);
    }
}
