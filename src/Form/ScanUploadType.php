<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ScanUploadType extends AbstractType
{
    private const LANGUAGE_NAMES = ['fr' => 'Français', 'en' => 'English', 'ja' => '日本語', 'de' => 'Deutsch', 'es' => 'Español', 'it' => 'Italiano', 'pt' => 'Português'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['languages'] as $code) {
            $choices[self::LANGUAGE_NAMES[$code] ?? strtoupper($code)] = $code;
        }

        $builder
            ->add('language', ChoiceType::class, [
                'label' => 'Langue des cartes',
                'choices' => $choices,
                'help' => 'Toutes les cartes de la photo doivent être dans cette langue.',
            ])
            ->add('photo', FileType::class, [
                'label' => 'Photo',
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp', 'data-preview' => 'scan-preview'],
                'constraints' => [
                    new NotBlank(message: 'Choisissez une photo.'),
                    new File(
                        maxSize: '40M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        maxSizeMessage: 'Cette photo est trop lourde ({{ size }} {{ suffix }}) : {{ limit }} {{ suffix }} au maximum.',
                        mimeTypesMessage: 'Format non pris en charge : utilisez une photo JPEG, PNG ou WebP.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['languages' => []]);
        $resolver->setAllowedTypes('languages', 'string[]');
    }
}
