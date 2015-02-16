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
		$dm = $this->container->get('doctrine')->getEntityManager();
		$hash = hash('sha512',$passwd);
		
		// TODO Tester la sécurité
    	$queryUser = $dm->createQuery('SELECT u FROM ImerirNoyauBundle:Utilisateur u ');
    	$users = $queryUser->getResult();
    	$u = $users[0];
		
    	$token = new UsernamePasswordToken($u->getUsername(), $u->getPassword(), 'main', $u->getRoles());
    	$context = $this->container->get('security.context');
    	$context->setToken($token);
    	
		return $u->getRoles()[0];
	}
}
