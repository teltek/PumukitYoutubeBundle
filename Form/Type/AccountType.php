<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Form\Type;

use Pumukit\NewAdminBundle\Form\Type\Base\TextI18nType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccountType extends AbstractType
{
    private $translator;
    private $locale;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->translator = $options['translator'];
        $this->locale = $options['locale'];

        $builder
            ->add(
                'i18n_title',
                TextI18nType::class,
                [
                    'attr' => ['aria-label' => $this->translator->trans('Title', [], null, $this->locale)],
                    'label' => $this->translator->trans('Title', [], null, $this->locale),
                ]
            )
            ->add(
                'login',
                TextType::class,
                [
                    'label' => $this->translator->trans('login', [], null, $this->locale),
                    'attr' => ['class' => 'form-control'],
                    'required' => true,
                ]
            )
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('translator');
        $resolver->setRequired('locale');
    }

    public function getBlockPrefix(): string
    {
        return 'pumukit_youtube_account';
    }
}
