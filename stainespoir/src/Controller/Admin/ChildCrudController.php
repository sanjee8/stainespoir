<?php
namespace App\Controller\Admin;

use App\Entity\Child;
use App\Entity\User;
use App\Entity\ParentProfile;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ChildCrudController extends AbstractCrudController
{
    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $rs
    ) {}

    public static function getEntityFqcn(): string
    {
        return Child::class;
    }

    /**
     * On met la vue “groupée par niveau” par défaut.
     */
    public function index(AdminContext $context): Response
    {
        $url = $this->container->get(AdminUrlGenerator::class)
            ->setController(self::class)
            ->setAction('groupedView')
            ->generateUrl();

        return new RedirectResponse($url);
    }

    public function configureActions(Actions $actions): Actions
    {
        $grouped = Action::new('grouped', 'Vue par niveau', 'fa fa-layer-group')
            ->linkToCrudAction('groupedView');

        $export = Action::new('exportCsv', 'Exporter CSV', 'fa fa-file-csv')
            ->linkToCrudAction('exportCsv');

        return $actions
            ->add(Crud::PAGE_INDEX, $grouped)
            ->add(Crud::PAGE_INDEX, $export);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInPlural('Enfants')
            ->setEntityLabelInSingular('Enfant')
            ->setDefaultSort(['lastName' => 'ASC', 'firstName' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        $req = $this->rs->getCurrentRequest();
        $parentId = (int)($req?->query->get('parentId', 0) ?? 0);

        // Association vers le parent (adapter au mapping réel : field "parent" (Profile) et/ou "user")
        $parentField = AssociationField::new('parent', 'Parent');

        if ($pageName === Crud::PAGE_INDEX) {
            $parentField = $parentField->formatValue(function($value, $entity) {
                $p = null;
                if (method_exists($entity, 'getParent')) { $p = $entity->getParent(); }
                elseif (method_exists($entity, 'getUser')) { $p = $entity->getUser(); }

                if (!$p) return '—';

                if ($p instanceof User) {
                    $full = trim(($p->getProfile()?->getFirstName() ?? '').' '.($p->getProfile()?->getLastName() ?? ''));
                    return $full !== '' ? $full : ($p->getEmail() ?? '—');
                }
                if ($p instanceof ParentProfile) {
                    $full = trim(($p->getFirstName() ?? '').' '.($p->getLastName() ?? ''));
                    return $full !== '' ? $full : '—';
                }
                return (string) $p ?: '—';
            });

            return [
                TextField::new('firstName', 'Prénom'),
                TextField::new('lastName',  'Nom'),
                TextField::new('level',     'Niveau'),
                $parentField,
                BooleanField::new('canLeaveAlone', 'Retour seul ?'),
                BooleanField::new('isApproved', 'Validé'),
            ];
        }

        if ($pageName === Crud::PAGE_NEW) {
            $fields = [];

            if ($parentId > 0) {
                // Read-only d’info parent (l’association est fixée côté serveur)
                $fields[] = TextField::new('parent_display', 'Parent')
                    ->setFormTypeOptions(['mapped' => false, 'disabled' => true, 'data' => $this->parentDisplay($parentId)])
                    ->setHelp('Rattaché automatiquement (depuis le dossier parent).');
            } else {
                $fields[] = $parentField->setRequired(true);
            }

            $fields[] = TextField::new('firstName', 'Prénom');
            $fields[] = TextField::new('lastName',  'Nom');
            $fields[] = TextField::new('level',     'Niveau')->hideOnIndex();
            $fields[] = TextField::new('school',    'Établissement')->hideOnIndex();
            $fields[] = TextareaField::new('notes', 'Notes')->hideOnIndex();
            $fields[] = BooleanField::new('canLeaveAlone', 'Retour seul autorisé ?');
            $fields[] = BooleanField::new('isApproved', 'Validé');

            return $fields;
        }

        // EDIT
        return [
            $parentField->setRequired(true),
            TextField::new('firstName', 'Prénom'),
            TextField::new('lastName',  'Nom'),
            TextField::new('level',     'Niveau')->hideOnIndex(),
            TextField::new('school',    'Établissement')->hideOnIndex(),
            TextareaField::new('notes', 'Notes')->hideOnIndex(),
            BooleanField::new('canLeaveAlone', 'Retour seul autorisé ?'),
            BooleanField::new('isApproved', 'Validé'),
        ];
    }

    public function createEntity(string $entityFqcn)
    {
        $child = new Child();
        $req = $this->rs->getCurrentRequest();
        $pid = (int)($req?->query->get('parentId', 0) ?? 0);

        if ($pid > 0) {
            /** @var User|null $parentUser */
            $parentUser = $this->em->getRepository(User::class)->find($pid);
            if ($parentUser) {
                if (method_exists($child, 'setParent')) {
                    $profile = method_exists($parentUser, 'getProfile') ? $parentUser->getProfile() : null;
                    if ($profile instanceof ParentProfile) {
                        $child->setParent($profile);
                    }
                }
                if (method_exists($child, 'setUser')) {
                    $child->setUser($parentUser);
                }
            }
        }

        return $child;
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->ensureBackrefFromQuery($entityInstance);
        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->ensureBackrefFromQuery($entityInstance);
        parent::updateEntity($em, $entityInstance);
    }

    private function ensureBackrefFromQuery($entity): void
    {
        if (!$entity instanceof Child) return;

        $req = $this->rs->getCurrentRequest();
        $pid = (int)($req?->query->get('parentId', 0) ?? 0);
        if ($pid <= 0) return;

        /** @var User|null $parentUser */
        $parentUser = $this->em->getRepository(User::class)->find($pid);
        if (!$parentUser) return;

        $alreadyOk =
            (method_exists($entity, 'getParent') && $entity->getParent() instanceof ParentProfile) ||
            (method_exists($entity, 'getUser')   && $entity->getUser()   instanceof User);

        if ($alreadyOk) return;

        if (method_exists($entity, 'setParent')) {
            $profile = method_exists($parentUser, 'getProfile') ? $parentUser->getProfile() : null;
            if ($profile instanceof ParentProfile) {
                $entity->setParent($profile);
            }
        }
        if (method_exists($entity, 'setUser')) {
            $entity->setUser($parentUser);
        }
    }

    private function parentDisplay(int $parentId): string
    {
        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->find($parentId);
        if (!$user) return '—';

        $first = $user->getProfile()?->getFirstName();
        $last  = $user->getProfile()?->getLastName();
        $full  = trim(($first ?? '').' '.($last ?? ''));
        return $full !== '' ? $full : ($user->getEmail() ?? '—');
    }

    // ======================= VUE GROUPÉE (par niveau) =======================

    public function groupedView(AdminContext $ctx, Request $req): Response
    {
        // Filtres GET
        $q       = trim((string)$req->query->get('q',''));
        $levels  = (array)$req->query->all('levels'); // ex: ['CP','CE1',...]
        $approved= $req->query->get('approved');      // '1'|'0'|null
        $alone   = $req->query->get('alone');         // '1'|'0'|null
        $school  = trim((string)$req->query->get('school',''));
        $perGrp  = min(200, max(10, (int)$req->query->get('per', 50)));
        $page    = max(1, (int)$req->query->get('page', 1));
        $expand  = (string)$req->query->get('expand',''); // niveau à déplier complètement

        $allLevels = ['CP','CE1','CE2','CM1','CM2','6e','5e','4e','3e','2nde','1ère','Terminale'];

        // Query
        $qb = $this->em->createQueryBuilder()
            ->select('c,u,p')->from(Child::class,'c')
            ->leftJoin('c.parent','p')
            ->leftJoin('p.user','u')
            ->orderBy('c.level','ASC')
            ->addOrderBy('c.lastName','ASC')
            ->addOrderBy('c.firstName','ASC');

        if ($q !== '') {
            $qb->andWhere('c.firstName LIKE :q OR c.lastName LIKE :q')->setParameter('q','%'.$q.'%');
        }
        if ($levels) {
            $qb->andWhere('c.level IN (:lv)')->setParameter('lv', $levels);
        }
        if ($approved === '1') {
            if ($this->hasField(Child::class, 'isApproved')) $qb->andWhere('c.isApproved = true');
        } elseif ($approved === '0') {
            if ($this->hasField(Child::class, 'isApproved')) $qb->andWhere('c.isApproved = false OR c.isApproved IS NULL');
        }
        if ($alone === '1') {
            if ($this->hasField(Child::class,'canLeaveAlone')) $qb->andWhere('c.canLeaveAlone = true');
        } elseif ($alone === '0') {
            if ($this->hasField(Child::class,'canLeaveAlone')) $qb->andWhere('c.canLeaveAlone = false OR c.canLeaveAlone IS NULL');
        }
        if ($school !== '') {
            if ($this->hasField(Child::class,'school')) $qb->andWhere('c.school LIKE :sc')->setParameter('sc','%'.$school.'%');
        }

        /** @var Child[] $rows */
        $rows = $qb->getQuery()->getResult();

        // Groupement par niveau
        $groups = []; // level => [children=>[], counts=>['total'=>..,'approved'=>..,'alone'=>..]]
        foreach ($rows as $c) {
            $lv = (string)($c->getLevel() ?? '—');
            if (!isset($groups[$lv])) {
                $groups[$lv] = [
                    'children' => [],
                    'counts'   => ['total'=>0,'approved'=>0,'alone'=>0],
                ];
            }
            $groups[$lv]['children'][] = $c;
            $groups[$lv]['counts']['total']++;
            if ($this->hasField(Child::class,'isApproved') && $c->isApproved()) $groups[$lv]['counts']['approved']++;
            if ($this->hasField(Child::class,'canLeaveAlone') && $c->isCanLeaveAlone()) $groups[$lv]['counts']['alone']++;
        }

        // Pagination des groupes (par *niveaux*)
        $levelsOrder = array_values(array_unique(array_merge($allLevels, array_keys($groups))));
        $levelsOrder = array_values(array_filter($levelsOrder, fn($l)=>isset($groups[$l]))); // conserve seulement ceux présents
        $totalGroups = count($levelsOrder);
        $pages = (int)ceil(max(1,$totalGroups) / 5); // 5 niveaux par page
        $page  = min($page, max(1,$pages));
        $slice = array_slice($levelsOrder, ($page-1)*5, 5);

        // Limite enfants par groupe (sauf si expand = ce niveau)
        $limited = [];
        foreach ($slice as $lv) {
            $g = $groups[$lv];
            $hasMore = false;
            $children = $g['children'];
            if ($expand !== $lv) {
                if (count($children) > $perGrp) {
                    $children = array_slice($children, 0, $perGrp);
                    $hasMore = true;
                }
            }
            $limited[$lv] = [
                'children' => $children,
                'counts'   => $g['counts'],
                'has_more' => $hasMore,
            ];
        }

        return $this->render('admin/child/grouped.html.twig', [
            'groups'     => $limited,
            'levels_all' => $allLevels,
            'q'          => $q,
            'levels'     => $levels,
            'approved'   => $approved,
            'alone'      => $alone,
            'school'     => $school,
            'per'        => $perGrp,
            'page'       => $page,
            'pages'      => $pages,
            'expand'     => $expand,
            'totalGroups'=> $totalGroups,
        ]);
    }

    public function exportCsv(Request $req): StreamedResponse
    {
        // même filtres que groupedView
        $q       = trim((string)$req->query->get('q',''));
        $levels  = (array)$req->query->all('levels');
        $approved= $req->query->get('approved');
        $alone   = $req->query->get('alone');
        $school  = trim((string)$req->query->get('school',''));

        $qb = $this->em->createQueryBuilder()
            ->select('c,u,p')->from(Child::class,'c')
            ->leftJoin('c.parent','p')
            ->leftJoin('p.user','u')
            ->orderBy('c.level','ASC')
            ->addOrderBy('c.lastName','ASC')
            ->addOrderBy('c.firstName','ASC');

        if ($q !== '') {
            $qb->andWhere('c.firstName LIKE :q OR c.lastName LIKE :q')->setParameter('q','%'.$q.'%');
        }
        if ($levels) {
            $qb->andWhere('c.level IN (:lv)')->setParameter('lv', $levels);
        }
        if ($approved === '1') {
            if ($this->hasField(Child::class, 'isApproved')) $qb->andWhere('c.isApproved = true');
        } elseif ($approved === '0') {
            if ($this->hasField(Child::class, 'isApproved')) $qb->andWhere('c.isApproved = false OR c.isApproved IS NULL');
        }
        if ($alone === '1') {
            if ($this->hasField(Child::class,'canLeaveAlone')) $qb->andWhere('c.canLeaveAlone = true');
        } elseif ($alone === '0') {
            if ($this->hasField(Child::class,'canLeaveAlone')) $qb->andWhere('c.canLeaveAlone = false OR c.canLeaveAlone IS NULL');
        }
        if ($school !== '') {
            if ($this->hasField(Child::class,'school')) $qb->andWhere('c.school LIKE :sc')->setParameter('sc','%'.$school.'%');
        }

        /** @var Child[] $rows */
        $rows = $qb->getQuery()->getResult();

        $resp = new StreamedResponse(function() use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 (Excel)
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['Prénom','Nom','Niveau','Établissement','Parent','Email parent','Validé','Retour seul ?']);
            foreach ($rows as $c) {
                $parentName = '—'; $parentEmail = '—';
                $profile = method_exists($c, 'getParent') ? $c->getParent() : null;
                if ($profile instanceof ParentProfile) {
                    $parentName = trim(($profile->getFirstName() ?? '').' '.($profile->getLastName() ?? '')) ?: '—';
                    $user = method_exists($profile,'getUser') ? $profile->getUser() : null;
                    if ($user instanceof User) {
                        $parentEmail = $user->getEmail() ?? '—';
                    }
                }
                fputcsv($out, [
                    $c->getFirstName() ?? '',
                    $c->getLastName() ?? '',
                    $c->getLevel() ?? '',
                    method_exists($c,'getSchool') ? ($c->getSchool() ?? '') : '',
                    $parentName,
                    $parentEmail,
                    $this->hasField(Child::class,'isApproved') ? ($c->isApproved() ? 'Oui' : 'Non') : '',
                    $this->hasField(Child::class,'canLeaveAlone') ? ($c->isCanLeaveAlone() ? 'Oui' : 'Non') : '',
                ]);
            }
            fclose($out);
        });
        $resp->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $resp->headers->set('Content-Disposition', 'attachment; filename="enfants-export.csv"');

        return $resp;
    }

    private function hasField(string $fqcn, string $field): bool
    {
        $cm = $this->em->getClassMetadata($fqcn);
        return $cm->hasField($field) || $cm->hasAssociation($field) || in_array($field, array_keys($cm->fieldMappings ?? []), true);
    }
}
