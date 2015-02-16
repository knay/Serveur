<?php

namespace Imerir\NoyauBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use BeSimple\SoapBundle\ServiceDefinition\Annotation as Soap;
use Symfony\Component\DependencyInjection\ContainerAware;

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
	public function loginAction($username,$passwd) {
		//$dm = $this->getDoctrine()->getManager();

		$dm = $this->getEntityManager();
		$queryUser = $dm->createQuery('SELECT username FROM ImerirNoyauBundle:Utilisateur');
		//requête mango
		/*
        $repo = $dm->getRepository('AcmeUserBundle:User');
        $user = $repo->findOneByUsername($username);
		*/
		//requête mysql
		/*
		$user = $this->getDoctrine()->getRepository('ImerirEntity:Utilisateur')->find($username);
		$hash = hash('sha512',$passwd);
		$mdp = $this->getDoctrine()->getRepository('ImerirEntity:Utilisateur')->find($hash);

		*/
		$hash = hash('sha512',$passwd);
		return $hash;
		/*
        if (!$user && !mdp) {
            throw $this->createNotFoundException('No demouser found!');
        }

        $token = new UsernamePasswordToken($user, $passwd, 'main', $user->getRoles());

        $context = $this->get('security.context');
        $context->setToken($token);

        $router = $this->get('router');
        $url = $router->generate('dashboard_show');

		return "";
		*/
	}
}
