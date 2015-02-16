<?php

namespace Imerir\NoyauBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use BeSimple\SoapBundle\ServiceDefinition\Annotation as Soap;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class SoapController extends ContainerAware
{
	/*
    public function indexAction()
    {
    	$server = new \SoapServer('bundles/imerirnoyau/soap/alba.wsdl');
    	$server->setObject($this->get('soap_service'));
    	
    	$response = new Response();
    	$response->headers->set('Content-Type', 'text/xml; charset=ISO-8859-1');
    	
    	ob_start();
    	$server->handle();
    	$response->setContent(ob_get_clean());
    	
    	return $response;
    }
	*/
	/**
	 * @Soap\Method("hello")
	 * @Soap\Param("name", phpType = "string")
	 * @Soap\Result(phpType = "string")
	 */
	public function helloAction($name)
	{
		return sprintf('Hello %s!', $name);
	}

	/**
	 * @Soap\Method("login")
	 * @Soap\Param("username",phpType="string")
	 * @Soap\Param("passwd",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function loginAction($username, $passwd) {
		//recupere la classe Utilisateur mappé à la table User dans la base de données
		$dm = $this->container->get('doctrine')->getEntityManager();

		//on récupère l'encoder du password dans la base de données pour ensuite hasher le mot de passe et tester
		//si le mot de passe est le même
		$userManager = $this->container->get('fos_user.user_manager');
		$user = $userManager->loadUserByUsername($username);
		$encoder = $this->container->get('security.encoder_factory')->getEncoder($user);
		$hash = $encoder->encodePassword($passwd, $user->getSalt());

		//DEBUG
		echo $hash;

		//DQL langage doctrine les paramètres sont mis dans un tableau
		$sql = "SELECT u FROM ImerirNoyauBundle:Utilisateur u WHERE u.username = :username AND u.password = :passwd";
		$queryUser = $dm->createQuery($sql)->setParameters(array('username'=>$username,'passwd'=>$hash));
		//on récupère toutes les lignes de la requête
		$users = $queryUser->getResult();
		//on lit la première ligne
		$u = $users[0];

		$token = new UsernamePasswordToken($u->getUsername(), $u->getPassword(), 'main', $u->getRoles());
		$context = $this->container->get('security.context');
		$context->setToken($token);
		
		return $u->getRoles()[0];
	}
}
