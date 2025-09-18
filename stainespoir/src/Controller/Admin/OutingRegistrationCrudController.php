<?php
namespace App\Controller\Admin;

use App\Entity\OutingRegistration;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

final class OutingRegistrationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return OutingRegistration::class;
    }

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Par défaut, on redirige l’index vers la vue groupée “par sortie”.
     */
    public function index(AdminContext $context): Response
    {
        $url = $this->container->get(AdminUrlGenerator::class)
            ->setController(self::class)
            ->setAction('groupedView')
            ->generateUrl();

        return new RedirectResponse($url);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Inscription')
            ->setEntityLabelInPlural('Inscriptions')
            ->setSearchFields(['child.firstName','child.lastName','outing.title','status','signatureName','signaturePhone'])
            ->setDefaultSort(['outing.startsAt' => 'DESC', 'id' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('outing','Sortie'))
            ->add(EntityFilter::new('child','Enfant'))
            ->add(ChoiceFilter::new('status','Statut')->setChoices($this->statusChoices()))
            ->add(DateTimeFilter::new('signedAt','Signé le'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $pdf = Action::new('pdf', 'Attestation', 'fa fa-file-pdf')
            ->linkToRoute('admin_outing_pdf', fn(OutingRegistration $r) => ['id' => $r->getId()])
            ->displayIf(fn(OutingRegistration $r) => null !== $r->getSignedAt());

        $markAttended = Action::new('markAttended', 'Présent', 'fa fa-check')
            ->linkToCrudAction('markAttended')
            ->displayIf(fn(OutingRegistration $r) => $r->getStatus() !== 'attended');

        $markAbsent = Action::new('markAbsent', 'Absent', 'fa fa-times')
            ->linkToCrudAction('markAbsent')
            ->displayIf(fn(OutingRegistration $r) => $r->getStatus() !== 'absent');

        $markDeclined = Action::new('markDeclined', 'Refusée', 'fa fa-ban')
            ->linkToCrudAction('markDeclined')
            ->displayIf(fn(OutingRegistration $r) => $r->getStatus() !== 'declined');

        // Vue custom “par sortie”
        $grouped = Action::new('grouped', 'Vue par sortie', 'fa fa-layer-group')
            ->linkToCrudAction('groupedView');

        // Export CSV des résultats filtrés
        $export = Action::new('exportCsv', 'Exporter CSV', 'fa fa-file-csv')
            ->linkToCrudAction('groupedExportCsv');

        return $actions
            ->add(Crud::PAGE_INDEX, $grouped)
            ->add(Crud::PAGE_INDEX, $export)
            ->add(Crud::PAGE_INDEX, $pdf)
            ->add(Crud::PAGE_DETAIL, $pdf)
            ->add(Crud::PAGE_INDEX, $markAttended)
            ->add(Crud::PAGE_INDEX, $markAbsent)
            ->add(Crud::PAGE_INDEX, $markDeclined);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield AssociationField::new('outing','Sortie')
            ->setFormTypeOption('choice_label', 'title')
            ->formatValue(fn($v, OutingRegistration $r)
            => $r->getOuting()?->getTitle() ?? '—');

        yield AssociationField::new('child','Enfant')
            ->setFormTypeOption('choice_label', function($c) {
                return method_exists($c,'getFirstName') ? ($c->getFirstName().' '.$c->getLastName()) : (string)$c;
            })
            ->formatValue(fn($v, OutingRegistration $r)
            => trim(($r->getChild()?->getFirstName() ?? '').' '.($r->getChild()?->getLastName() ?? '')) ?: '—');

        yield ChoiceField::new('status','Statut')
            ->setChoices($this->statusChoices())
            ->renderAsBadges([
                'invited'   => 'secondary',
                'confirmed' => 'success',
                'declined'  => 'danger',
                'attended'  => 'success',
                'absent'    => 'danger',
            ]);

        yield DateTimeField::new('signedAt','Signé le')->onlyOnIndex();

        yield TextField::new('signatureName','Signataire')->hideOnIndex();
        yield TextField::new('signaturePhone','Téléphone')->hideOnIndex();
        yield TextareaField::new('healthNotes','Infos santé')->hideOnIndex();

        if (property_exists(OutingRegistration::class, 'signatureIp')) {
            yield TextField::new('signatureIp','IP')->onlyOnDetail();
        }
        if (property_exists(OutingRegistration::class, 'signatureUserAgent')) {
            yield TextareaField::new('signatureUserAgent','User-Agent')->onlyOnDetail();
        }
        if (property_exists(OutingRegistration::class, 'signatureImage')) {
            yield TextareaField::new('signatureImage','Signature (image)')
                ->onlyOnDetail()
                ->setTemplatePath('admin/fields/signature_image.html.twig');
        }
    }

    private function statusChoices(): array
    {
        return [
            'Invitée'   => 'invited',
            'Signée'    => 'confirmed',
            'Refusée'   => 'declined',
            'Présent'   => 'attended',
            'Absent'    => 'absent',
        ];
    }

    // --- Actions rapides ---

    public function markAttended(AdminContext $ctx): RedirectResponse
    {
        /** @var OutingRegistration $r */
        $r = $ctx->getEntity()->getInstance();
        $r->setStatus('attended');
        $this->em->flush();
        $this->addFlash('success','Marqué présent.');
        return $this->redirect($ctx->getReferrer());
    }

    public function markAbsent(AdminContext $ctx): RedirectResponse
    {
        /** @var OutingRegistration $r */
        $r = $ctx->getEntity()->getInstance();
        $r->setStatus('absent');
        $this->em->flush();
        $this->addFlash('success','Marqué absent.');
        return $this->redirect($ctx->getReferrer());
    }

    public function markDeclined(AdminContext $ctx): RedirectResponse
    {
        /** @var OutingRegistration $r */
        $r = $ctx->getEntity()->getInstance();
        $r->setStatus('declined');
        $this->em->flush();
        $this->addFlash('success','Marqué refusé.');
        return $this->redirect($ctx->getReferrer());
    }

    // --- Vue “par sortie” ---

    public function groupedView(AdminContext $ctx, Request $req, \App\Repository\OutingRegistrationRepository $repo): Response
    {
        // Filtres GET
        $q         = trim((string)$req->query->get('q',''));
        $statuses  = (array)$req->query->all('status');
        $from      = $req->query->get('from'); // YYYY-MM-DD
        $to        = $req->query->get('to');   // YYYY-MM-DD
        $onlyFuture= $req->query->getBoolean('future', false);
        $page      = max(1, (int)$req->query->get('page', 1));
        $perOutings= min(20, max(1, (int)$req->query->get('per', 5)));
        $limitRegs = min(200, max(5,  (int)$req->query->get('limit', 20)));
        $expandId  = $req->query->getInt('expand', 0);

        $qb = $repo->createQueryBuilder('r')
            ->join('r.outing','o')->addSelect('o')
            ->join('r.child','c')->addSelect('c')
            ->orderBy('o.startsAt','DESC')
            ->addOrderBy('c.lastName','ASC')
            ->addOrderBy('c.firstName','ASC');

        if ($q !== '') {
            $qb->andWhere('o.title LIKE :q OR c.firstName LIKE :q OR c.lastName LIKE :q')
                ->setParameter('q', '%'.$q.'%');
        }
        if ($statuses) {
            $qb->andWhere('r.status IN (:st)')->setParameter('st', $statuses);
        }
        if ($onlyFuture) {
            $qb->andWhere('o.startsAt >= :now')->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')));
        }
        if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $qb->andWhere('o.startsAt >= :from')->setParameter('from', new \DateTimeImmutable($from.' 00:00:00', new \DateTimeZone('Europe/Paris')));
        }
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $qb->andWhere('o.startsAt <= :to')->setParameter('to', new \DateTimeImmutable($to.' 23:59:59', new \DateTimeZone('Europe/Paris')));
        }

        /** @var OutingRegistration[] $rows */
        $rows = $qb->getQuery()->getResult();

        // Groupement par sortie
        $allGroups = []; // [outingId => ['outing'=>Outing,'regs'=>[...],'counts'=>[], 'total'=>int]]
        foreach ($rows as $r) {
            $o = $r->getOuting(); $oid = $o->getId();
            if (!isset($allGroups[$oid])) {
                $allGroups[$oid] = [
                    'outing' => $o,
                    'regs'   => [],
                    'counts' => ['invited'=>0,'confirmed'=>0,'declined'=>0,'attended'=>0,'absent'=>0],
                    'total'  => 0,
                ];
            }
            $allGroups[$oid]['regs'][] = $r;
            $allGroups[$oid]['total']++;
            $st = $r->getStatus();
            if (isset($allGroups[$oid]['counts'][$st])) {
                $allGroups[$oid]['counts'][$st]++;
            }
        }

        // Pagination par sorties
        $outingIds   = array_keys($allGroups);
        $totalOutings= count($outingIds);
        $pages       = (int)ceil(max(1, $totalOutings) / $perOutings);
        $page        = min($page, max(1, $pages));
        $slice       = array_slice($outingIds, ($page-1)*$perOutings, $perOutings);

        // Applique la limite d’inscriptions par sortie (sauf expand)
        $groups = [];
        foreach ($slice as $oid) {
            $g = $allGroups[$oid];
            $regs = $g['regs'];
            $hasMore = false;
            if ($expandId && $expandId === $oid) {
                // pas de limite pour la sortie développée
            } else {
                if (count($regs) > $limitRegs) {
                    $regs = array_slice($regs, 0, $limitRegs);
                    $hasMore = true;
                }
            }
            $g['regs'] = $regs;
            $g['has_more'] = $hasMore;
            $groups[$oid] = $g;
        }

        $ctrlFqcn = self::class;

        return $this->render('admin/outing_registration/grouped.html.twig', [
            'groups'       => $groups,
            'ctrlFqcn'     => $ctrlFqcn,
            'q'            => $q,
            'statuses'     => $statuses,
            'from'         => $from,
            'to'           => $to,
            'future'       => $onlyFuture,
            'page'         => $page,
            'pages'        => $pages,
            'per'          => $perOutings,
            'limit'        => $limitRegs,
            'totalOutings' => $totalOutings,
            'expandId'     => $expandId,
        ]);
    }

    public function groupedExportCsv(Request $req, \App\Repository\OutingRegistrationRepository $repo): StreamedResponse
    {
        $q         = trim((string)$req->query->get('q',''));
        $statuses  = (array)$req->query->all('status');
        $from      = $req->query->get('from');
        $to        = $req->query->get('to');
        $onlyFuture= $req->query->getBoolean('future', false);

        $qb = $repo->createQueryBuilder('r')
            ->join('r.outing','o')->addSelect('o')
            ->join('r.child','c')->addSelect('c')
            ->orderBy('o.startsAt','DESC')
            ->addOrderBy('c.lastName','ASC')
            ->addOrderBy('c.firstName','ASC');

        if ($q !== '') {
            $qb->andWhere('o.title LIKE :q OR c.firstName LIKE :q OR c.lastName LIKE :q')
                ->setParameter('q', '%'.$q.'%');
        }
        if ($statuses) {
            $qb->andWhere('r.status IN (:st)')->setParameter('st', $statuses);
        }
        if ($onlyFuture) {
            $qb->andWhere('o.startsAt >= :now')->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')));
        }
        if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $qb->andWhere('o.startsAt >= :from')->setParameter('from', new \DateTimeImmutable($from.' 00:00:00', new \DateTimeZone('Europe/Paris')));
        }
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $qb->andWhere('o.startsAt <= :to')->setParameter('to', new \DateTimeImmutable($to.' 23:59:59', new \DateTimeZone('Europe/Paris')));
        }

        $rows = $qb->getQuery()->getResult();

        $resp = new StreamedResponse(function() use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM pour Excel
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['Sortie','Date/heure','Lieu','Enfant','Statut','Signé le','Signataire','Téléphone']);
            foreach ($rows as $r) {
                /** @var OutingRegistration $r */
                $o = $r->getOuting(); $c = $r->getChild();
                fputcsv($out, [
                    $o?->getTitle() ?? '',
                    $o?->getStartsAt()?->format('Y-m-d H:i') ?? '',
                    $o?->getLocation() ?? '',
                    trim(($c?->getFirstName() ?? '').' '.($c?->getLastName() ?? '')),
                    $r->getStatus(),
                    $r->getSignedAt()?->format('Y-m-d H:i') ?? '',
                    $r->getSignatureName() ?? '',
                    $r->getSignaturePhone() ?? '',
                ]);
            }
            fclose($out);
        });
        $resp->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $resp->headers->set('Content-Disposition', 'attachment; filename="inscriptions-export.csv"');

        return $resp;
    }
}
