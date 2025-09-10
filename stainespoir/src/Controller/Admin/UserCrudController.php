<?php
namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

class UserCrudController extends AbstractCrudController
{
    public function __construct(protected EntityManagerInterface $em) {}

    public static function getEntityFqcn(): string { return User::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInPlural('Parents')
            ->setEntityLabelInSingular('Parent')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(25);
    }

    public function configureFields(string $pageName): iterable
    {
        // On n’expose PAS le switch "isApproved".
        yield EmailField::new('email', 'Email')->onlyOnIndex();
        yield EmailField::new('email', 'Email')->onlyOnDetail();
        yield DateTimeField::new('approvedAt', 'Validé le')->onlyOnIndex();
    }
}
