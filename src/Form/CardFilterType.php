<?php

namespace App\Form;

use App\Admin\CardFilter;
use App\Entity\CardStatus;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CardFilterType extends CardCriteriaType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('status', EnumType::class, [
                'label' => 'Statut',
                'required' => false,
                'placeholder' => 'Tous',
                'class' => CardStatus::class,
                'choice_label' => static fn (CardStatus $status) => CardStatus::Active === $status ? 'Active' : 'Obsolète',
            ])
            ->add('missingImage', CheckboxType::class, ['label' => 'Image manquante', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', CardFilter::class);
    }
}
