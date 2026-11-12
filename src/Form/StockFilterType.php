<?php

namespace App\Form;

use App\Stock\StockCriteria;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class StockFilterType extends CardCriteriaType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('minQuantity', IntegerType::class, ['label' => 'Quantité min.', 'required' => false, 'attr' => ['min' => 1]])
            ->add('maxQuantity', IntegerType::class, ['label' => 'Quantité max.', 'required' => false, 'attr' => ['min' => 1]])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', StockCriteria::class);
    }
}
