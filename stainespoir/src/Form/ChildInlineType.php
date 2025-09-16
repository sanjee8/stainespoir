<?php
namespace App\Form;

use App\Entity\Child;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChildInlineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'Prénom'])
            ->add('lastName',  TextType::class, ['label' => 'Nom'])
            ->add('dob', DateType::class, [
                'label' => 'Date de naissance',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('level', ChoiceType::class, [
                'label' => 'Niveau',
                'choices' => [
                    'CE2'=>'CE2','CM1'=>'CM1','CM2'=>'CM2','6e'=>'6e','5e'=>'5e','4e'=>'4e',
                    '3e'=>'3e','2nde'=>'2nde','1ère'=>'1ère','Terminale'=>'Terminale',
                ],
                'placeholder' => '— Sélectionner —',
            ])
            ->add('school', TextType::class, ['label' => 'Établissement', 'required' => false])
            ->add('notes',  TextareaType::class, ['label' => 'Notes', 'required' => false, 'attr'=>['rows'=>2]])
            ->add('isApproved', CheckboxType::class, ['label' => 'Validé', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Child::class]);
    }
}
