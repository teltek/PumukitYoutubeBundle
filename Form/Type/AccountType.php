<?php

namespace Pumukit\YoutubeBundle\Form\Type;

use Pumukit\NewAdminBundle\Form\Type\Base\TextI18nType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatorInterface;

class AccountType extends AbstractType
{
    private $translator;
    private $locale;

    public function __construct(TranslatorInterface $translator, $locale = 'en')
    {
        $this->translator = $translator;
        $this->locale = $locale;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('i18n_title', TextI18nType::class, array(
                'attr' => array('aria-label' => $this->translator->trans('Title', array(), null, $this->locale)),
                'label' => $this->translator->trans('Title', array(), null, $this->locale),
            ))
            ->add('login', TextType::class, array(
            'label' => $this->translator->trans('login', array(), null, $this->locale),
            'attr' => array('class' => 'form-control'),
            'required' => true,
        ));
    }

    public function getBlockPrefix()
    {
        return 'pumukit_youtube_account';
    }
}
