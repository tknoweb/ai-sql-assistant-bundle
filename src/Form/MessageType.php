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
 * Next message of a conversation: a new request, or the answer to the pending question of the assistant.
 */
class MessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('message', TextareaType::class, [
                'label' => false,
                'constraints' => [new NotBlank(), new Length(max: NewConversationType::QUESTION_MAX_LENGTH)],
                'attr' => ['rows' => 2, 'placeholder' => 'messagePlaceholder'],
            ])
            ->add('send', SubmitType::class, ['label' => 'send']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => ChatController::TRANSLATION_DOMAIN]);
    }
}
