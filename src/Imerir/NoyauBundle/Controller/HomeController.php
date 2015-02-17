<?php

namespace Imerir\NoyauBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class HomeController extends Controller
{
    public function indexAction()
    {
		$dm = $this->container->get('doctrine')->getEntityManager();
		$passwd='alba';
		$username='alba';

		$userManager = $this->get('fos_user.user_manager');
		$user = $userManager->loadUserByUsername($username);
		$encoder = $this->get('security.encoder_factory')->getEncoder($user);
		$hash = $encoder->encodePassword($passwd, $user->getSalt());

		echo $hash;
		// TODO Tester la sécurité
		$sql = "SELECT u FROM ImerirNoyauBundle:Utilisateur u WHERE u.username = :username AND u.password = :passwd";
		//$queryUser = $dm->createQuery('SELECT u FROM ImerirNoyauBundle:Utilisateur u ');
		$queryUser = $dm->createQuery($sql)->setParameters(array('username'=>$username,'passwd'=>$hash));
		$users = $queryUser->getResult();
		$u = $users[0];

		$token = new UsernamePasswordToken($u->getUsername(), $u->getPassword(), 'main', $u->getRoles());
		$context = $this->container->get('security.context');
		$context->setToken($token);
    	
		// TODO inserer ce code pour trouver l'utilisateur
		/*$userManager = $this->container->get('fos_user.user_manager');
		$user = $userManager->findUserByUsername($this->container->get('security.context')
				->getToken()
				->getUser());*/
		
        return $this->render('ImerirNoyauBundle:default:index.html.twig');
    }
}
