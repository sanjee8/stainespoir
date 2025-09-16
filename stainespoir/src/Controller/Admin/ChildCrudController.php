<?php
namespace App\Controller\Admin;

use App\Entity\Child;
use App\Entity\User;
use App\Entity\ParentProfile;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use Symfony\Component\HttpFoundation\RequestStack;

class ChildCrudController extends AbstractCrudController
{
    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $rs
    ) {}

    public static function getEntityFqcn(): string
    {
        return Child::class;
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

        // Association vers le parent (adapter à ton mapping : 'parent' ou 'user')
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
            $fields[] = BooleanField::new('canLeaveAlone', 'Retour seul autorisé ?'); // ✅ NEW
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
            BooleanField::new('canLeaveAlone', 'Retour seul autorisé ?'), // ✅ EDIT (ajouté)
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
}
