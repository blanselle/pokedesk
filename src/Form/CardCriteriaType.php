<?php

namespace App\Form;

use App\Catalog\CardCriteria;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The search fields common to every card list. */
class CardCriteriaType extends AbstractType
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
            ->add('category', ChoiceType::class, [
                'label' => 'Catégorie',
                'required' => false,
                'placeholder' => 'Toutes',
                'choices' => array_combine($options['categories'], $options['categories']),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CardCriteria::class,
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
            'languages' => [],
            'rarities' => [],
            'categories' => [],
        ]);
        $resolver->setAllowedTypes('languages', 'string[]');
        $resolver->setAllowedTypes('rarities', 'string[]');
        $resolver->setAllowedTypes('categories', 'string[]');
    }

    // No prefix: plain, shareable query parameters such as ?q=pikachu&language=fr.
    public function getBlockPrefix(): string
    {
        return '';
    }
}
