<?php

namespace App\Form;

use App\Admin\CardFilter;
use App\Entity\CardStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CardFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', SearchType::class, ['label' => 'Nom ou identifiant', 'required' => false])
            ->add('language', ChoiceType::class, [
                'label' => 'Langue',
                'required' => false,
                'placeholder' => 'Toutes',
                'choices' => array_combine($options['languages'], $options['languages']),
            ])
            ->add('extension', TextType::class, ['label' => 'Extension', 'required' => false, 'attr' => ['placeholder' => 'base1, Jungle…']])
            ->add('number', TextType::class, ['label' => 'Numéro', 'required' => false])
            ->add('rarity', ChoiceType::class, [
                'label' => 'Rareté',
                'required' => false,
                'placeholder' => 'Toutes',
                'choices' => array_combine($options['rarities'], $options['rarities']),
            ])
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
        $resolver->setDefaults([
            'data_class' => CardFilter::class,
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
            'languages' => [],
            'rarities' => [],
        ]);
        $resolver->setAllowedTypes('languages', 'string[]');
        $resolver->setAllowedTypes('rarities', 'string[]');
    }

    // No prefix: plain, shareable query parameters such as ?q=pikachu&language=fr.
    public function getBlockPrefix(): string
    {
        return '';
    }
}
