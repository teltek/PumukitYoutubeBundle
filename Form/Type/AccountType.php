<?php

namespace Pumukit\YoutubeBundle\Form\Type;

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
        $builder->add('name', TextType::class, array(
                    'label' => $this->translator->trans('name', array(), null, $this->locale),
                    'attr' => array('class' => 'form-control'),
                    'required' => true
                ))->add('login', TextType::class, array(
                    'label' => $this->translator->trans('login', array(), null, $this->locale),
                    'attr' => array('class' => 'form-control'),
                    'required' => true,
                ));
    }

    public function getName()
    {
        return 'pumukit_youtube_account';
    }
}
