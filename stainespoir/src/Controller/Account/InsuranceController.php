<?php
namespace App\Controller\Account;

use App\Entity\Child;
use App\Entity\Insurance;
use App\Entity\User;
use App\Enum\InsuranceStatus;
use App\Enum\InsuranceType;
use App\Form\InsuranceUploadType;
use App\Repository\InsuranceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/mon-compte/assurances')]
class InsuranceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('', name: 'account_insurance_index', methods: ['GET','POST'])]
    public function index(Request $request, InsuranceRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user || !$user->getProfile()) {
            throw $this->createAccessDeniedException();
        }

        $parent   = $user->getProfile();
        $children = $parent->getChildren(); // adapte si nom différent

        $currentYear = $this->computeSchoolYear();

        // Deux formulaires par enfant (RC + HEALTH)
        $forms = [];
        foreach ($children as $child) {
            foreach ([InsuranceType::RC, InsuranceType::HEALTH] as $type) {
                $form = $this->createForm(InsuranceUploadType::class, null, [
                    'type'        => $type,
                    'school_year' => $currentYear,
                    'action'      => $this->generateUrl('account_insurance_upload', [
                        'id'   => $child->getId(),
                        'type' => $type->value,
                    ]),
                ]);
                $forms[$child->getId()][$type->value] = $form->createView();
            }
        }

        // Récupérer existants (pour affichage statuts)
        $existingByChild = [];
        if (\count($children) > 0) {
            $insurances = $repo->createQueryBuilder('i')
                ->andWhere('i.child IN (:kids)')
                ->setParameter('kids', $children)
                ->orderBy('i.uploadedAt', 'DESC')
                ->getQuery()->getResult();

            foreach ($insurances as $ins) {
                $existingByChild[$ins->getChild()->getId()][] = $ins;
            }
        }

        return $this->render('account/insurance_tab.html.twig', [
            'children'        => $children,
            'forms'           => $forms,
            'current_year'    => $currentYear,
            'existingByChild' => $existingByChild,
        ]);
    }

    #[Route('/upload/{id}/{type}', name: 'account_insurance_upload', methods: ['POST'])]
    public function upload(
        Child $id,
        string $type,
        Request $request,
        InsuranceRepository $repo
    ): Response {
        $child = $id;

        /** @var User $user */
        $user = $this->getUser();
        if (!$user || !$user->getProfile()) {
            throw $this->createAccessDeniedException();
        }
        // Vérifie que l'enfant appartient bien au parent connecté (adapte si ton getter diffère)
        if ($child->getParent() !== $user->getProfile()) {
            throw $this->createAccessDeniedException();
        }

        // Type d'assurance sûr (gère un type invalide proprement)
        try {
            $insuranceType = InsuranceType::from($type);
        } catch (\ValueError) {
            $this->addFlash('danger', 'Type d’assurance invalide.');
            return $this->redirectToRoute('app_account', [
                'enfant' => $child->getId(),
                'tab'    => 'assurance',
            ]);
        }

        $schoolYear = $this->computeSchoolYear();

        $form = $this->createForm(InsuranceUploadType::class, null, [
            'type'        => $insuranceType,
            'school_year' => $schoolYear,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Formulaire invalide.');
            return $this->redirectToRoute('app_account', [
                'enfant' => $child->getId(),
                'tab'    => 'assurance',
            ]);
        }

        /** @var UploadedFile|null $file */
        $file = $form->get('file')->getData();
        if (!$file) {
            $this->addFlash('danger', 'Aucun fichier reçu.');
            return $this->redirectToRoute('app_account', [
                'enfant' => $child->getId(),
                'tab'    => 'assurance',
            ]);
        }

        // 1) Métadonnées AVANT move()
        $clientName = (string) $file->getClientOriginalName();
        $clientMime = (string) ($file->getClientMimeType() ?: 'application/octet-stream');
        $clientSize = (int) ($file->getSize() ?? 0);

        // 2) Récup/Créa de l'entité unique (child+type+year)
        $ins = $repo->findOneBy([
            'child'      => $child,
            'type'       => $insuranceType,
            'schoolYear' => $schoolYear,
        ]) ?? (new Insurance())
            ->setChild($child)
            ->setType($insuranceType)
            ->setSchoolYear($schoolYear);

        // 3) Move avec gestion d’erreur claire
        try {
            $rel = $this->moveFile($file, $child->getId(), $schoolYear, $insuranceType->value);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Impossible d’enregistrer le fichier. Vérifiez les droits d’écriture.');
            return $this->redirectToRoute('app_account', [
                'enfant' => $child->getId(),
                'tab'    => 'assurance',
            ]);
        }

        // 4) Taille disque après move (fallback sur taille client)
        $absPath = rtrim($this->getParameter('app.insurance_dir'), '/\\')
            . DIRECTORY_SEPARATOR . $child->getId()
            . DIRECTORY_SEPARATOR . $schoolYear
            . DIRECTORY_SEPARATOR . basename($rel['rel']);
        $diskSize  = @filesize($absPath);
        $finalSize = is_int($diskSize) && $diskSize > 0 ? $diskSize : $clientSize;

        // 5) MAJ entité
        $ins->setPath($rel['rel'])
            ->setOriginalName($clientName)
            ->setMimeType($clientMime)
            ->setSize($finalSize)
            ->setUploadedAt(new \DateTimeImmutable())
            ->setStatus(InsuranceStatus::PENDING)
            ->setValidatedBy(null)
            ->setValidatedAt(null);

        $this->em->persist($ins);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Document %s envoyé pour %s (%s).',
            $insuranceType->label(),
            $child->getFirstname().' '.$child->getLastname(),
            $schoolYear
        ));

        // Redirection demandée : /mon-compte?enfant={id}&tab=assurance
        return $this->redirectToRoute('app_account', [
            'enfant' => $child->getId(),
            'tab'    => 'assurance',
        ]);
    }

    private function computeSchoolYear(): string
    {
        // Année scolaire FR: de sept (N) à août (N+1)
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $y = (int) $today->format('Y');
        $m = (int) $today->format('n');
        $start = ($m >= 9) ? $y : $y - 1;
        return $start . '-' . ($start + 1);
    }

    /**
     * Déplace le fichier vers public/uploads/assurances/{childId}/{schoolYear}/{type}-uniq.ext
     * Retourne ['abs' => chemin absolu, 'rel' => chemin relatif public]
     */
    private function moveFile(UploadedFile $file, int $childId, string $schoolYear, string $type): array
    {
        $base = $this->getParameter('app.insurance_dir');
        $fs   = new Filesystem();
        $dir  = $base.'/'.$childId.'/'.$schoolYear;

        if (!$fs->exists($dir)) {
            $fs->mkdir($dir, 0775);
        }

        $ext      = $file->guessExtension() ?: 'bin';
        $filename = sprintf('%s-%s.%s', $type, bin2hex(random_bytes(4)), $ext);
        $file->move($dir, $filename);

        $abs = $dir.'/'.$filename;
        $rel = sprintf('/uploads/assurances/%d/%s/%s', $childId, $schoolYear, $filename);
        return ['abs' => $abs, 'rel' => $rel];
    }
}
