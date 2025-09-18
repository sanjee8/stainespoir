<?php
namespace App\Controller\Admin;

use App\Entity\Insurance;
use App\Enum\InsuranceStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class InsuranceCrudController extends AbstractCrudController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AdminUrlGenerator $adminUrlGenerator,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Insurance::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInPlural('Assurances')
            ->setEntityLabelInSingular('Assurance')
            ->setDefaultSort(['uploadedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('child')->setLabel('Enfant');
        yield TextField::new('schoolYear', 'Année scolaire');

        // Si votre propriété 'type' est un enum (InsuranceType), vous pouvez laisser les labels string ici,
        // mais assurez-vous que l’EA sait convertir (sinon mappez vers vos constantes enum).
        yield ChoiceField::new('type', 'Type')->setChoices([
            'Responsabilité civile' => 'RC',
            'Assurance maladie'     => 'HEALTH',
        ])->renderAsBadges();

        yield TextField::new('path', 'Fichier')
            ->formatValue(fn($value) => $value ? sprintf('<a href="%s" target="_blank" rel="noopener">Ouvrir</a>', $value) : null)
            ->onlyOnIndex()
            ->renderAsHtml();

        yield TextareaField::new('adminComment', 'Commentaire admin')->hideOnIndex();

        yield ChoiceField::new('status', 'Statut')->setChoices([
            'En attente' => InsuranceStatus::PENDING,
            'Validée'    => InsuranceStatus::APPROVED,
            'Refusée'    => InsuranceStatus::REJECTED,
        ])->renderAsBadges();

        yield DateTimeField::new('uploadedAt', 'Déposé le');
        yield AssociationField::new('validatedBy', 'Validé par')->onlyOnIndex();
        yield DateTimeField::new('validatedAt', 'Validé le')->onlyOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $approve = Action::new('approve', 'Valider')
            ->linkToCrudAction('approveAction')
            ->addCssClass('btn btn-success');

        $reject = Action::new('reject', 'Refuser')
            ->linkToCrudAction('rejectAction')
            ->addCssClass('btn btn-danger');

        return $actions
            ->add(Crud::PAGE_INDEX, $approve)
            ->add(Crud::PAGE_DETAIL, $approve)
            ->add(Crud::PAGE_INDEX, $reject)
            ->add(Crud::PAGE_DETAIL, $reject);
    }

    public function approveAction(AdminContext $context)
    {
        /** @var Insurance $insurance */
        $insurance = $context->getEntity()->getInstance();
        $insurance->setStatus(InsuranceStatus::APPROVED);
        $insurance->setValidatedBy($this->getUser());
        $insurance->setValidatedAt(new \DateTimeImmutable());

        $this->em->flush();
        $this->addFlash('success', 'Assurance validée.');

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($url);
    }

    public function rejectAction(AdminContext $context)
    {
        /** @var Insurance $insurance */
        $insurance = $context->getEntity()->getInstance();
        $insurance->setStatus(InsuranceStatus::REJECTED);
        $insurance->setValidatedBy($this->getUser());
        $insurance->setValidatedAt(new \DateTimeImmutable());

        $this->em->flush();
        $this->addFlash('warning', 'Assurance refusée.');

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($url);
    }
}
