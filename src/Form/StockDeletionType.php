<?php

namespace App\Form;

use App\Stock\StockDeletion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class StockDeletionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ids', CollectionType::class, ['entry_type' => IntegerType::class, 'allow_add' => true, 'label' => false])
            ->add('delete', SubmitType::class, ['label' => 'Supprimer la sélection'])
            ->add('confirm', SubmitType::class, ['label' => 'Confirmer la suppression'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StockDeletion::class]);
    }
}
