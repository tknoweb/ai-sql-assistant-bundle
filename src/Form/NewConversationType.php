<?php

namespace Tknoweb\AiSqlAssistantBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Tknoweb\AiSqlAssistantBundle\Controller\ChatController;

/**
 * First question of a conversation.
 */
class NewConversationType extends AbstractType
{
    public const QUESTION_MAX_LENGTH = 2000;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('question', TextareaType::class, [
                'label' => 'question',
                'constraints' => [new NotBlank(), new Length(max: self::QUESTION_MAX_LENGTH)],
                'attr' => ['rows' => 3, 'placeholder' => 'questionPlaceholder'],
            ])
            ->add('ask', SubmitType::class, ['label' => 'ask']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => ChatController::TRANSLATION_DOMAIN]);
    }
}
