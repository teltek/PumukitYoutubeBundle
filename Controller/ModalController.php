<?php

namespace Pumukit\YoutubeBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\NewAdminBundle\Form\Type\TagType;


class ModalController extends Controller
{
    
    /**
     * 
     * pumukitnewadmin_tag_create
     * @Template()
     */
    
    public function indexAction(Request $request)
    {        
        $dm = $this->get('doctrine_mongodb')->getManager();
        $repo = $dm->getRepository('PumukitSchemaBundle:Tag');

        $tag = new Tag();

        $translator = $this->get('translator');
        $locale = $request->getLocale();

        $form = $this->createForm(new TagType($translator, $locale), $tag);

        if (($request->isMethod('PUT') || $request->isMethod('POST')) && $form->bind($request)->isValid()) {
            try {
                $dm->persist($tag);
                $dm->flush();
            } catch (\Exception $e) {
                return new JsonResponse(array("status" => $e->getMessage()), 409);
            }

            return $this->redirect($this->generateUrl('pumukitnewadmin_tag_list'));
        }
       
        return array('tag' => $tag, 'form' => $form->createView());
    }

}