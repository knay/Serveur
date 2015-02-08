<?php

namespace Imerir\NoyeauBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction($name)
    {
        return $this->render('ImerirNoyeauBundle:Default:index.html.twig', array('name' => $name));
    }
}
