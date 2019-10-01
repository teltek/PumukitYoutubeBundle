<?php

namespace Pumukit\YoutubeBundle\Form\Type;

use Pumukit\NewAdminBundle\Form\Type\Base\TextI18nType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatorInterface;

class YoutubePlaylistType extends AbstractType
{
    /**
     * @var TranslatorInterface
     */
    private $translator;
    private $locale;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->translator = $options['translator'];
        $this->locale = $options['locale'];
        $builder->add(
            'i18n_title',
            TextI18nType::class,
            [
                'label'    => $this->translator->trans('Title', [], null, $this->locale),
                'attr'     => ['class' => 'form-control', 'maxlength' => 150],
                'required' => true,
            ]
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('translator');
        $resolver->setRequired('locale');
    }

    public function getBlockPrefix(): string
    {
        return 'pumukit_youtube_playlist';
    }
}
