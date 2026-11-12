<?php

namespace App\Form;

use App\Stock\QuantityChange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class StockQuantityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('previous', IntegerType::class)
            ->add('quantity', IntegerType::class, [
                'label' => false,
                'attr' => ['min' => 0, 'max' => QuantityChange::MAX, 'class' => 'text-center', 'aria-label' => 'Quantité'],
            ])
            // First button of the form: it is the one the Enter key triggers.
            ->add('apply', SubmitType::class, ['label' => 'Appliquer', 'attr' => ['class' => 'visually-hidden', 'tabindex' => -1]])
            ->add('less', SubmitType::class, ['label' => '−', 'attr' => ['class' => 'btn btn-outline-secondary', 'aria-label' => 'Retirer un exemplaire']])
            ->add('more', SubmitType::class, ['label' => '+', 'attr' => ['class' => 'btn btn-outline-secondary', 'aria-label' => 'Ajouter un exemplaire']])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuantityChange::class]);
    }
}
