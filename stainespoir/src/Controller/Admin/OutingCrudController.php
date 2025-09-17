<?php
namespace App\Controller\Admin;

use App\Entity\Outing;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

final class OutingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return Outing::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sortie')
            ->setEntityLabelInPlural('Sorties')
            ->setSearchFields(['title','location','description'])
            ->setDefaultSort(['startsAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('title','Titre'))
            ->add(TextFilter::new('location','Lieu'))
            ->add(DateTimeFilter::new('startsAt','Date/heure'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $registrations = Action::new('registrations', 'Inscriptions', 'fa fa-users')
            ->linkToUrl(function(\App\Entity\Outing $o) {
                $fqcn = urlencode(\App\Controller\Admin\OutingRegistrationCrudController::class);
                // ouvre la liste des inscriptions filtrée par cette sortie
                return sprintf('/admin?crudAction=index&crudControllerFqcn=%s&filters[outing]=%d', $fqcn, $o->getId());
            });

        return $actions
            ->add(Crud::PAGE_INDEX, $registrations)
            ->add(Crud::PAGE_DETAIL, $registrations);
        // NE PAS ré-ajouter NEW/EDIT/DETAIL ici : ils existent déjà
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title', 'Titre');
        yield DateTimeField::new('startsAt', 'Date/heure');
        yield TextField::new('location', 'Lieu')->hideOnIndex();
        yield UrlField::new('imageUrl', 'Image (URL)')->hideOnIndex();
        yield TextareaField::new('description', 'Description')->hideOnIndex();
    }
}
