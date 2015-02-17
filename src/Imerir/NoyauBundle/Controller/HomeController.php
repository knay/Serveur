<?php

namespace Imerir\NoyauBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class HomeController extends Controller
{
    public function indexAction()
    {
    	$this->container->get('user_service')->isOk('ROLE_GERANT');    	
		// TODO inserer ce code pour trouver l'utilisateur
		/*$userManager = $this->container->get('fos_user.user_manager');
		$user = $userManager->findUserByUsername($this->container->get('security.context')
				->getToken()
				->getUser());*/
		
        return $this->render('ImerirNoyauBundle:default:index.html.twig');
    }
}
