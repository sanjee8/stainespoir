<?php
namespace App\Controller\Admin;

use App\Repository\OutingRegistrationRepository;
use App\Service\PdfGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class OutingPdfController extends AbstractController
{
    #[Route('/admin/outing/{id}/attestation.pdf', name: 'admin_outing_pdf', methods: ['GET'])]
    public function pdf(
        int $id,
        OutingRegistrationRepository $regs,
        PdfGenerator $pdf
    ): Response {
        $reg = $regs->find($id);
        if (!$reg) {
            throw $this->createNotFoundException('Inscription introuvable.');
        }

        // Réutilise le même template d’attestation que côté parents
        // (change le chemin si ton template s’appelle autrement)
        $html = $this->renderView('account/outing_attestation.html.twig', [
            'reg'       => $reg,
            'fromAdmin' => true, // au cas où tu veux adapter le texte dans le Twig
        ]);

        $binary = $pdf->render($html, 'A4');

        return new Response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="attestation-'.$id.'.pdf"',
        ]);
    }
}
