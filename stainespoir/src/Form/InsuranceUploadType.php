<?php
namespace App\Form;

use App\Enum\InsuranceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InsuranceUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // On verrouille le type pour le formulaire (passé via l'option).
        $builder
            ->add('type', HiddenType::class, [
                'data' => $options['type']?->value,
            ])
            ->add('schoolYear', HiddenType::class, [
                'data' => $options['school_year'] ?? '',
            ])
            ->add('file', FileType::class, [
                'mapped' => false,
                'label' => 'Fichier (PDF/JPG/PNG, max 5 Mo)',
                'constraints' => [
                    new Assert\NotBlank(message: 'Merci de joindre un fichier'),
                    new Assert\File(
                        maxSize: '5M',
                        mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'],
                        mimeTypesMessage: 'Formats acceptés: PDF, JPG, PNG'
                    )
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
        $resolver->setRequired(['type', 'school_year']); // InsuranceType + string
        $resolver->setAllowedTypes('type', [InsuranceType::class]);
        $resolver->setAllowedTypes('school_year', ['string']);
    }
}
