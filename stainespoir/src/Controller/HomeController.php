<?php
namespace App\Controller;

use App\Repository\OutingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(OutingRepository $outingRepo): Response
    {
        $upcoming = $outingRepo->findUpcoming(3);

        return $this->render('home/index.html.twig', [
            'upcomingOutings' => $upcoming,
        ]);
    }
}
