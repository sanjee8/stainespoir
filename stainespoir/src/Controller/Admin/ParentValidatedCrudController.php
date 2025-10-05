<?php
namespace App\Controller\Admin;

use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use App\Entity\User;
use App\Entity\Child;
use App\Entity\OutingRegistration;
use App\Entity\Message;
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
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use App\Form\ChildInlineType;
use App\Entity\ParentProfile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ParentValidatedCrudController extends UserCrudController
{
    public function __construct(
        EntityManagerInterface $em,
        private AdminUrlGenerator $urlGen,
        private UserPasswordHasherInterface $hasher,
        private RequestStack $requestStack,
    ) {
        parent::__construct($em);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInPlural('Parents validés')
            ->setEntityLabelInSingular('Parent validé')
            ->setDefaultSort(['approvedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_INDEX) {
            return [
                TextField::new('fullName', 'Nom prénom'),
                EmailField::new('email', 'Email'),
                DateTimeField::new('approvedAt', 'Validé le'),
            ];
        }

        if ($pageName === Crud::PAGE_EDIT) {
            return [
                FormField::addPanel('Compte (User)')->setIcon('fa fa-user-check'),
                EmailField::new('email', 'Email'),
                BooleanField::new('isApproved', 'Compte validé'),

                // 🔐 Nouveau mot de passe (non mappé)
                TextField::new('newPassword', 'Nouveau mot de passe')
                    ->setFormType(PasswordType::class)
                    ->setFormTypeOptions([
                        'mapped' => false,
                        'required' => false,
                        'attr' => ['autocomplete' => 'new-password'],
                    ])
                    ->onlyOnForms(),

                FormField::addPanel('Profil parent')->setIcon('fa fa-id-card'),
                TextField::new('profile.firstName', 'Prénom')->setFormTypeOption('property_path', 'profile.firstName'),
                TextField::new('profile.lastName',  'Nom')->setFormTypeOption('property_path', 'profile.lastName'),
                TelephoneField::new('profile.phone','Téléphone')->setFormTypeOption('property_path', 'profile.phone'),
                TextField::new('profile.address',   'Adresse')->setFormTypeOption('property_path', 'profile.address')->setColumns(12),
                TextField::new('profile.postalCode','Code postal')->setFormTypeOption('property_path', 'profile.postalCode')->setColumns(4),
                TextField::new('profile.city',      'Ville')->setFormTypeOption('property_path', 'profile.city')->setColumns(8),

                FormField::addPanel('Enfants rattachés')->setIcon('fa fa-children'),
                CollectionField::new('children', 'Enfants')
                    ->setFormTypeOption('property_path', 'profile.children')
                    ->setEntryType(ChildInlineType::class)
                    ->setFormTypeOptions(['by_reference' => false])
                    ->allowAdd()->allowDelete()
                    ->setEntryIsComplex(true),
            ];
        }

        return parent::configureFields($pageName);
    }

    /** Filtre : uniquement les comptes validés */
    public function createIndexQueryBuilder(
        SearchDto $searchDto, EntityDto $entityDto,
        FieldCollection $fields, FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $alias = $qb->getRootAliases()[0];
        return $qb->andWhere("$alias.isApproved = :ok")->setParameter('ok', true);
    }

    /** Ajouter bouton “Dossier” (DETAIL) sur l’index */
    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);
        $actions = $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
        $actions = $actions->update(Crud::PAGE_INDEX, Action::DETAIL, fn(Action $a) => $a->setLabel('Dossier'));
        return $actions;
    }

    /** DETAIL : parent + enfants + sorties signées + messages */
    public function detail(AdminContext $context): Response
    {
        /** @var User $user */
        $user = $context->getEntity()->getInstance();

        $profile  = method_exists($user, 'getProfile') ? $user->getProfile() : null;
        $children = $this->findChildrenForParent($user, $profile);
        $childIds = array_values(array_filter(array_map(fn($c)=> method_exists($c,'getId') ? (int)$c->getId() : 0, $children)));

        // Sorties signées (tous les enfants)
        $signedOutings = [];
        if (!empty($childIds)) {
            $okStatuses = ['confirmed','attended'];
            $qb = $this->em->getRepository(OutingRegistration::class)->createQueryBuilder('r')
                ->leftJoin('r.child', 'c')->addSelect('c')
                ->leftJoin('r.outing','o')->addSelect('o')
                ->andWhere('c.id IN (:ids)')->setParameter('ids', $childIds)
                ->andWhere('(r.signedAt IS NOT NULL OR r.status IN (:st))')->setParameter('st', $okStatuses)
                ->orderBy('o.startsAt', 'DESC');

            $rows = $qb->getQuery()->getResult();
            foreach ($rows as $r) {
                $child = $r->getChild();
                $outing= $r->getOuting();

                $signedOutings[] = [
                    'childId'   => $child?->getId() ?? 0,
                    'childName' => trim(($child?->getFirstName() ?? '').' '.($child?->getLastName() ?? '')),
                    'title'     => $outing?->getTitle() ?? '—',
                    'startsAt'  => $outing?->getStartsAt()?->format('d/m/Y H:i') ?? '—',
                    'location'  => $outing?->getLocation() ?? null,
                    'description'=> $outing?->getDescription() ?? null,
                    'status'    => $r->getStatus() ?? null,
                    'signedAt'  => $r->getSignedAt()?->format('d/m/Y H:i') ?? null,
                ];
            }
        }

        // Messages groupés par enfant
        $messagesByChild = [];
        if (!empty($childIds)) {
            $qb = $this->em->getRepository(Message::class)->createQueryBuilder('m')
                ->leftJoin('m.child', 'c')->addSelect('c')
                ->andWhere('c.id IN (:ids)')->setParameter('ids', $childIds)
                ->orderBy('m.createdAt', 'DESC');
            $msgs = $qb->getQuery()->getResult();

            foreach ($msgs as $m) {
                $child = $m->getChild();
                $cid   = $child?->getId() ?? 0;
                $cname = trim(($child?->getFirstName() ?? '').' '.($child?->getLastName() ?? ''));

                if (!isset($messagesByChild[$cid])) {
                    $messagesByChild[$cid] = ['childName' => $cname, 'items' => []];
                }
                $messagesByChild[$cid]['items'][] = [
                    'from'    => $m->getFrom() ?? 'staff',
                    'body'    => $m->getBody() ?? '',
                    'created' => $m->getCreatedAt()?->format('d/m/Y H:i') ?? '—',
                    'read'    => $m->getReadAt() !== null,
                ];
            }
        }

        // Ajout chat vide pour enfants sans messages
        foreach ($children as $ch) {
            $cid   = $ch->getId();
            $cname = trim(($ch->getFirstName()??'').' '.($ch->getLastName()??''));
            if (!isset($messagesByChild[$cid])) {
                $messagesByChild[$cid] = ['childName' => $cname, 'items' => []];
            }
        }

        $addChildUrl = $this->urlGen
            ->setController(\App\Controller\Admin\ChildCrudController::class)
            ->setAction(Action::NEW)
            ->set('parentId', $user->getId())
            ->generateUrl();

        return $this->render('admin/parents/validated_dossier.html.twig', [
            'parent'          => $user,
            'profile'         => $profile,
            'children'        => $children,
            'signedOutings'   => $signedOutings,
            'messagesByChild' => $messagesByChild,
            'addChildUrl'     => $addChildUrl,
        ]);
    }

    private function findChildrenForParent(User $user, $profile = null): array
    {
        $repo = $this->em->getRepository(Child::class);
        $cm   = $this->em->getClassMetadata(Child::class);

        foreach (($cm->associationMappings ?? $cm->getAssociationMappings()) as $field => $map) {
            $target = $map['targetEntity'] ?? null;
            if ($target === User::class) {
                $list = $repo->findBy([$field => $user], ['lastName' => 'ASC', 'firstName' => 'ASC']);
                if (!empty($list)) return $list;
            }
        }

        if ($profile) {
            $profClass = get_class($profile);
            foreach (($cm->associationMappings ?? $cm->getAssociationMappings()) as $field => $map) {
                $target = $map['targetEntity'] ?? null;
                if ($target === $profClass) {
                    $list = $repo->findBy([$field => $profile], ['lastName' => 'ASC', 'firstName' => 'ASC']);
                    if (!empty($list)) return $list;
                }
            }
        }

        if ($user->getChildren()) return $user->getChildren()->toArray();
        if ($profile && $profile->getChildren()) return $profile->getChildren()->toArray();

        return [];
    }

    #[Route('/admin/parent-validated/{parentId}/send-message', name: 'admin_parent_send_message', methods: ['POST'])]
    public function sendMessage(int $parentId, Request $request): Response
    {
        $childId = (int) $request->request->get('child_id', 0);
        $text    = trim((string) $request->request->get('text', ''));
        $token   = (string) $request->request->get('_token', '');

        if (!$this->isCsrfTokenValid('admin_send_message_'.$childId, $token)) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirect($this->urlGen->setController(self::class)->setAction('detail')->setEntityId($parentId)->generateUrl());
        }

        if ($childId <= 0 || $text === '') {
            $this->addFlash('warning', 'Message vide.');
            return $this->redirect($this->urlGen->setController(self::class)->setAction('detail')->setEntityId($parentId)->generateUrl().'#chat-'.$childId);
        }

        $parent = $this->em->getRepository(User::class)->find($parentId);
        if (!$parent) {
            $this->addFlash('danger', 'Parent introuvable.');
            return $this->redirect($this->urlGen->setController(self::class)->setAction('index')->generateUrl());
        }

        $profile  = method_exists($parent, 'getProfile') ? $parent->getProfile() : null;
        $allowed  = $this->findChildrenForParent($parent, $profile);
        $allowedIds = array_map(fn($c) => $c->getId(), $allowed);

        if (!in_array($childId, $allowedIds, true)) {
            $this->addFlash('danger', 'Cet enfant n’appartient pas à ce parent.');
            return $this->redirect($this->urlGen->setController(self::class)->setAction('detail')->setEntityId($parentId)->generateUrl());
        }

        $child = $this->em->getRepository(Child::class)->find($childId);
        if (!$child) {
            $this->addFlash('danger', 'Enfant introuvable.');
            return $this->redirect($this->urlGen->setController(self::class)->setAction('detail')->setEntityId($parentId)->generateUrl());
        }

        $m = new Message();
        $m->setChild($child);
        $m->setFrom('staff');
        $m->setBody($text);
        $m->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($m);
        $this->em->flush();

        $this->addFlash('success', 'Message envoyé.');
        return $this->redirect($this->urlGen->setController(self::class)->setAction('detail')->setEntityId($parentId)->generateUrl().'#chat-'.$childId);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof User) {
            // 1) Profil manquant → création
            $profile = method_exists($entityInstance,'getProfile') ? $entityInstance->getProfile() : null;
            if (null === $profile) {
                $profile = new ParentProfile();
                $profile->setUser($entityInstance);
                $entityInstance->setProfile($profile);
                $em->persist($profile);
            }

            // 2) Back-ref enfants
            if ($profile && method_exists($profile,'getChildren')) {
                foreach ($profile->getChildren() as $child) {
                    if ($child instanceof Child && method_exists($child,'setParent') && $child->getParent() !== $profile) {
                        $child->setParent($profile);
                        $em->persist($child);
                    }
                }
            }

            // 3) 🔐 Nouveau mot de passe via RequestStack
            $request = $this->requestStack->getCurrentRequest();
            $plain = '';

            if ($request) {
                $post = $request->request->all();
                foreach ($post as $root => $payload) {
                    if (is_array($payload) && array_key_exists('newPassword', $payload)) {
                        $plain = (string) $payload['newPassword'];
                        break;
                    }
                }
            }

            if ($plain !== '') {
                $hash = $this->hasher->hashPassword($entityInstance, $plain);
                $entityInstance->setPassword($hash);
            }
        }

        parent::updateEntity($em, $entityInstance);
    }

    public function edit(AdminContext $context)
    {
        $entity = $context->getEntity()->getInstance();
        if ($entity instanceof User) {
            if (method_exists($entity, 'getProfile') && null === $entity->getProfile()) {
                $profile = new ParentProfile();
                $profile->setUser($entity);
                $entity->setProfile($profile);
                $this->em->persist($profile);
                $this->em->flush();
            }
        }
        return parent::edit($context);
    }
}
