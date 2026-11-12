<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

/** Password + confirmation field shared by registration and password reset. */
final class NewPasswordType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'Mot de passe',
                'attr' => ['autocomplete' => 'new-password'],
                'help' => '12 caractères minimum.',
            ],
            'second_options' => [
                'label' => 'Confirmez le mot de passe',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'Les deux mots de passe doivent être identiques.',
            'constraints' => [
                new NotBlank(message: 'Veuillez saisir un mot de passe.'),
                new Length(
                    min: 12,
                    max: 4096,
                    minMessage: 'Votre mot de passe doit contenir au moins {{ limit }} caractères.',
                ),
                new NotCompromisedPassword(message: 'Ce mot de passe a été divulgué lors d\'une fuite de données, choisissez-en un autre.'),
            ],
        ]);
    }

    public function getParent(): string
    {
        return RepeatedType::class;
    }
}
