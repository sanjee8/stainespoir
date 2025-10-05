<?php

namespace App\Controller\Admin;

use App\Repository\OutingRegistrationRepository;
use App\Service\PdfGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class OutingPdfController extends AbstractController
{
    #[Route('/admin/outing/registration/{id}/attestation.pdf', name: 'admin_outing_pdf', methods: ['GET'])]
    public function pdf(
        int $id,
        OutingRegistrationRepository $regs,
        PdfGenerator $pdf
    ): Response {
        $reg = $regs->find($id);
        if (!$reg) {
            throw $this->createNotFoundException('Inscription introuvable.');
        }

        // Optionnel : si tu veux exiger la signature comme côté parent :
        if (!$reg->getSignedAt()) {
            $this->addFlash('error', "Aucune signature enregistrée pour cette inscription.");
            // Redirige par exemple vers la page détail admin de la sortie/inscription
            return $this->redirectToRoute('admin'); // adapte si besoin
        }

        // 👇 même template PDF que le côté parent
        $binary = $pdf->render('pdf/outing_attestation.html.twig', [
            'reg'       => $reg,
            'fromAdmin' => true, // permet d’adapter quelques libellés si tu veux
        ]);

        if (!$binary || strlen($binary) < 100) {
            if ($this->getParameter('kernel.debug')) {
                return new Response(
                    "Le PDF généré est vide (".strlen((string)$binary)." octets).\n".
                    "Vérifie le template pdf/outing_attestation.html.twig et le service PdfGenerator.",
                    500,
                    ['Content-Type' => 'text/plain; charset=UTF-8']
                );
            }
            throw $this->createNotFoundException('PDF non disponible.');
        }

        // Affichage inline dans le navigateur (ou change pour attachment si tu préfères)
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            'attestation-sortie-'.$reg->getId().'.pdf'
        );

        return new Response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => $disposition,
        ]);
    }
}
