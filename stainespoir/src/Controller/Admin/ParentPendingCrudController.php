<?php
namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\Child;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ParentPendingCrudController extends UserCrudController
{
    public function __construct(
        EntityManagerInterface $em,
        private AdminUrlGenerator $urlGen
    ) {
        parent::__construct($em);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInPlural('Parents en attente')
            ->setEntityLabelInSingular('Parent en attente');
    }

    /** INDEX: une seule colonne "Nom prénom" */
    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_INDEX) {
            yield TextField::new('fullName', 'Nom prénom');
            return;
        }
        return parent::configureFields($pageName);
    }

    /** Filtre : uniquement non-validés */
    public function createIndexQueryBuilder(
        SearchDto $searchDto, EntityDto $entityDto,
        FieldCollection $fields, FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $alias = $qb->getRootAliases()[0];
        return $qb->andWhere("$alias.isApproved = :ok")->setParameter('ok', false);
    }

    /** Actions : une seule action “Dossier” → CRUD action ‘review’ */
    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::DETAIL);

        $review = Action::new('review', 'Dossier')
            ->linkToCrudAction('review')
            ->setCssClass('btn btn-secondary');

        return $actions->add(Crud::PAGE_INDEX, $review);
    }

    /**
     * Page Dossier (GET) + Traitement (POST)
     */
    public function review(AdminContext $context, Request $request): Response
    {
        /** @var User|null $user */
        $user = $context->getEntity()->getInstance();
        if (!$user) {
            $this->addFlash('danger', 'Parent introuvable.');
            return $this->redirect($this->urlGen->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl());
        }
        if (method_exists($user, 'isApproved') && $user->isApproved()) {
            $this->addFlash('info', 'Ce parent est déjà validé.');
            return $this->redirect($this->urlGen->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl());
        }

        // Profil + libellé de relation (selon le nom de méthode réel)
        $profile = method_exists($user, 'getProfile') ? $user->getProfile() : null;
        $relationLabel = null;
        if ($profile) {
            foreach ([
                         'getRelationToChild', 'getRelation', 'getGuardianRelation',
                         'getParentRelation', 'getLien', 'getLienAvecEnfant'
                     ] as $m) {
                if (method_exists($profile, $m)) { $relationLabel = $profile->{$m}(); break; }
            }
        }

        // Enfants rattachés
        $children = $this->findChildrenForParent($user, $profile);

        // VM pour Twig
        $childrenVm = array_map([$this, 'childToVm'], $children);

        // Soumission ?
        if ($request->isMethod('POST')) {
            $tokenId = 'pending_decision_' . $user->getId();
            if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $decision = (string) $request->request->get('decision', '');
            // récupère un tableau d’IDs cochés
            $approvedIds = array_map('intval', (array) $request->request->all('approved_children'));

            if ($decision === 'reject') {
                foreach ($children as $child) { $this->em->remove($child); }
                $this->em->remove($user);
                $this->em->flush();

                $this->addFlash('success', 'Parent et enfants supprimés (inscription refusée).');
                return $this->redirect($this->urlGen->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl());
            }

            if ($decision === 'approve') {
                if (method_exists($user, 'setIsApproved')) $user->setIsApproved(true);
                if (method_exists($user, 'getApprovedAt') && method_exists($user, 'setApprovedAt')) {
                    if (!$user->getApprovedAt()) { $user->setApprovedAt(new \DateTimeImmutable()); }
                }

                foreach ($children as $child) {
                    $id = method_exists($child,'getId') ? (int)$child->getId() : 0;
                    if (in_array($id, $approvedIds, true)) {
                        if (method_exists($child, 'setIsApproved')) { $child->setIsApproved(true); }
                    } else {
                        $this->em->remove($child);
                    }
                }
                $this->em->flush();

                $this->addFlash('success', 'Parent validé. Enfants cochés validés, non cochés supprimés.');
                return $this->redirect($this->urlGen->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl());
            }

            $this->addFlash('warning', 'Aucune action effectuée.');
        }

        // Affichage
        return $this->render('admin/pending/review_ea.html.twig', [
            'parent'        => $user,
            'profile'       => $profile,
            'relationLabel' => $relationLabel,
            'children'      => $childrenVm,
            'token_id'      => 'pending_decision_' . $user->getId(),
        ]);
    }

    /**
     * Trouve l’association de Child vers User (ou Profile) via le mapping Doctrine.
     */
    private function findChildrenForParent(User $user, $profile = null): array
    {
        $repo = $this->em->getRepository(Child::class);
        $cm   = $this->em->getClassMetadata(Child::class);

        // 1) Assoc vers User
        foreach (($cm->associationMappings ?? $cm->getAssociationMappings()) as $field => $map) {
            $target = $map['targetEntity'] ?? null;
            if ($target === User::class) {
                try {
                    $list = $repo->findBy([$field => $user], ['lastName' => 'ASC', 'firstName' => 'ASC']);
                    if (!empty($list)) return $list;
                } catch (\Throwable $e) { /* ignore */ }
            }
        }

        // 2) Assoc vers Profile
        if ($profile) {
            $profClass = get_class($profile);
            foreach (($cm->associationMappings ?? $cm->getAssociationMappings()) as $field => $map) {
                $target = $map['targetEntity'] ?? null;
                if ($target === $profClass) {
                    try {
                        $list = $repo->findBy([$field => $profile], ['lastName' => 'ASC', 'firstName' => 'ASC']);
                        if (!empty($list)) return $list;
                    } catch (\Throwable $e) { /* ignore */ }
                }
            }
        }

        // 3) Méthodes getChildren()
        if (method_exists($user, 'getChildren') && $user->getChildren()) {
            $list = $user->getChildren()->toArray();
            $this->sortByName($list);
            if (!empty($list)) return $list;
        }
        if ($profile && method_exists($profile, 'getChildren') && $profile->getChildren()) {
            $list = $profile->getChildren()->toArray();
            $this->sortByName($list);
            if (!empty($list)) return $list;
        }

        return [];
    }

    private function sortByName(array &$children): void
    {
        usort($children, function($a, $b){
            $la = method_exists($a,'getLastName') ? (string)$a->getLastName() : '';
            $lb = method_exists($b,'getLastName') ? (string)$b->getLastName() : '';
            $fa = method_exists($a,'getFirstName') ? (string)$a->getFirstName() : '';
            $fb = method_exists($b,'getFirstName') ? (string)$b->getFirstName() : '';
            return [$la,$fa] <=> [$lb,$fb];
        });
    }

    /** Transforme un Child en VM pour Twig (clés stables) */
    private function childToVm($child): array
    {
        $id   = method_exists($child,'getId') ? (int)$child->getId() : 0;
        $fn   = method_exists($child,'getFirstName') ? (string)$child->getFirstName() : '';
        $ln   = method_exists($child,'getLastName')  ? (string)$child->getLastName()  : '';
        $lvl  = method_exists($child,'getLevel')     ? (string)$child->getLevel()     : null;
        $sch  = method_exists($child,'getSchool')    ? (string)$child->getSchool()    : null;
        $notes= method_exists($child,'getNotes')     ? (string)$child->getNotes()     : null;

        // date de naissance : supporte plusieurs noms de getters
        $dobObj = null;
        foreach (['getDob','getDateOfBirth','getBirthDate','getBirthday'] as $m) {
            if (method_exists($child, $m)) { $dobObj = $child->{$m}(); break; }
        }
        $dob = ($dobObj instanceof \DateTimeInterface) ? $dobObj->format('d/m/Y') : null;

        // validé ?
        $approved = false;
        if (method_exists($child,'isApproved')) $approved = (bool)$child->isApproved();
        elseif (method_exists($child,'getIsApproved')) $approved = (bool)$child->getIsApproved();

        // ✅ retour seul autorisé ?
        $allow = false;
        if (method_exists($child, 'isCanLeaveAlone'))      { $allow = (bool)$child->isCanLeaveAlone(); }
        elseif (method_exists($child, 'getCanLeaveAlone')) { $allow = (bool)$child->getCanLeaveAlone(); }

        return [
            'id'            => $id,
            'firstName'     => $fn,
            'lastName'      => $ln,
            'level'         => $lvl,
            'dob'           => $dob,
            'school'        => $sch,
            'notes'         => $notes,
            'approved'      => $approved,
            'canLeaveAlone' => $allow,
        ];
    }
}
