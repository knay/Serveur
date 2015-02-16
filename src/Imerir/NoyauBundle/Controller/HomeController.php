<?php

namespace Imerir\NoyauBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class HomeController extends Controller
{
    public function indexAction()
    {
    	$dm = $this->container->get('doctrine')->getEntityManager();
    	$queryUser = $dm->createQuery('SELECT u FROM ImerirNoyauBundle:Utilisateur u');
    	$users = $queryUser->getResult();
    	$u = $users[0];
    	
    	$token = new UsernamePasswordToken($u->getUsername(), $u->getPassword(), 'main', $u->getRoles());
    	$context = $this->get('security.context');
    	$context->setToken($token);
    	
        return $this->render('ImerirNoyauBundle:default:index.html.twig');
    }
}
