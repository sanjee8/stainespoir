<?php
namespace App\Controller\Admin;

use App\Entity\Outing;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

final class OutingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Outing::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sortie')
            ->setEntityLabelInPlural('Sorties')
            ->setSearchFields(['title', 'location', 'description'])
            ->setDefaultSort(['startsAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('title', 'Titre'))
            ->add(TextFilter::new('location', 'Lieu'))
            ->add(DateTimeFilter::new('startsAt', 'Date/heure'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $registrations = Action::new('registrations', 'Inscriptions', 'fa fa-users')
            ->linkToUrl(function (Outing $o) {
                $fqcn = urlencode(\App\Controller\Admin\OutingRegistrationCrudController::class);
                // Ouvre la liste des inscriptions filtrée par cette sortie
                return sprintf('/admin?crudAction=index&crudControllerFqcn=%s&filters[outing]=%d', $fqcn, $o->getId());
            });

        $invite = Action::new('invite', 'Inviter', 'fa fa-paper-plane')
            ->linkToRoute('admin_outing_invite', fn (Outing $o) => ['id' => $o->getId()]);

        return $actions
            ->add(Crud::PAGE_INDEX, $registrations)
            ->add(Crud::PAGE_DETAIL, $registrations)
            ->add(Crud::PAGE_INDEX, $invite)
            ->add(Crud::PAGE_DETAIL, $invite);
        // NEW/EDIT/DETAIL existent déjà par défaut
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield TextField::new('title', 'Titre');

        yield DateTimeField::new('startsAt', 'Date/heure');

        yield TextField::new('location', 'Lieu')
            ->hideOnIndex();

        yield UrlField::new('imageUrl', 'Image (URL)')
            ->hideOnIndex();

        // ✅ Description avec éditeur riche (TinyMCE capté par data-editor="tinymce")
        yield TextareaField::new('description', 'Description (HTML)')
            ->hideOnIndex()
            ->setHelp('Utilisez la barre d’outils pour titres, gras, listes, liens, tableaux, etc.')
            ->setFormTypeOptions([
                'attr' => [
                    'rows' => 12,
                    'data-editor' => 'tinymce', // ← hook JS
                ],
            ]);

        // ✅ Limite d’enfants (par signatures)
        yield IntegerField::new('capacity', 'Limite d’enfants (signatures)')
            ->setHelp('Laissez vide pour illimité.')
            ->setFormTypeOption('attr', ['min' => 0]);
    }
}
