<?php
namespace App\Controller\Admin;

use App\Entity\Child;
use App\Entity\User;
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
        // ⚠️ On utilise des champs sûrs (présents dans ta BDD),
        // évite 'dob' si le nom exact diffère.
        yield AssociationField::new('parent', 'Parent')
            ->setRequired(true);

        yield TextField::new('firstName', 'Prénom');
        yield TextField::new('lastName',  'Nom');
        yield TextField::new('level',     'Niveau')->hideOnIndex();
        yield TextField::new('school',    'Établissement')->hideOnIndex();
        yield TextareaField::new('notes', 'Notes')->hideOnIndex();
        yield BooleanField::new('isApproved', 'Validé');
    }

    /** Pré-renseigne le parent depuis ?parentId=... (quand on clique "Ajouter un enfant" depuis le dossier parent) */
    public function createEntity(string $entityFqcn)
    {
        $child = new Child();
        $req = $this->rs->getCurrentRequest();
        $pid = $req?->query->getInt('parentId', 0);
        if ($pid > 0) {
            $parent = $this->em->getRepository(User::class)->find($pid);
            if ($parent && method_exists($child, 'setParent')) {
                $child->setParent($parent);
            }
        }
        return $child;
    }
}
