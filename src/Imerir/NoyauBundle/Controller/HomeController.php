<?php

namespace Imerir\NoyauBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Imerir\NoyauBundle\Entity\Utilisateur;

class HomeController extends Controller
{
    public function indexAction()
    {
    //recupere la classe Utilisateur mappé à la table User dans la base de données
		$dm = $this->container->get('doctrine')->getEntityManager();
		$username = 'lba';
		$passwd = 'alb';
		
		$sql = "SELECT u FROM ImerirNoyauBundle:Utilisateur u WHERE u.username = :username";
		$queryUser = $dm->createQuery($sql)->setParameters(array('username'=>$username));
		$users = $queryUser->getResult();
		if(count($users) === 0) {
			echo'mabite';
			 return $this->render('ImerirNoyauBundle:default:index.html.twig');;
		}
		

		//on récupère l'encoder du password dans la base de données pour ensuite hasher le mot de passe et tester
		//si le mot de passe est le même
		$userManager = $this->container->get('fos_user.user_manager');
		try {
			$user = $userManager->loadUserByUsername($username);
		}
		catch(UsernameNotFoundException $e) {
			echo $e->getMessage();
		}
		$encoder = $this->container->get('security.encoder_factory')->getEncoder($user);
		$hash = $encoder->encodePassword($passwd, $user->getSalt());
		//$hash = $encoder->encodePassword('', $user->getSalt());

		//DEBUG
		//echo $hash;

		//DQL langage doctrine les paramètres sont mis dans un tableau
		$sql = "SELECT u FROM ImerirNoyauBundle:Utilisateur u WHERE u.username = :username AND u.password = :passwd";
		$queryUser = $dm->createQuery($sql)->setParameters(array('username'=>$username, 'passwd'=>$hash));

		//on récupère toutes les lignes de la requête
		$users = $queryUser->getResult();
		//on teste si il y a bien un utilisateur username avec le mot de passe passwd
		if(count($users) !== 0) {
			//on lit la première lignes
			$u = $users[0];

			$token = new UsernamePasswordToken($u->getUsername(), $u->getPassword(), 'main', $u->getRoles());
			$context = $this->container->get('security.context');
			$context->setToken($token);

			$retourJson = array('token'=>$this->container->get('request')->cookies->get('PHPSESSID'),
				'username'=>$username,
				'role'=>$u->getRoles()[0]);
			return json_encode($retourJson);
		}
		else{
			echo 'FAILLL';
		}
		
        return $this->render('ImerirNoyauBundle:default:index.html.twig');
    }
}
