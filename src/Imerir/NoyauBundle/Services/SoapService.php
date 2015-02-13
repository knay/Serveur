<?php namespace Imerir\NoyauBundle\Services;

class SoapService
{
	private $container;
	
    public function __construct($cont) {
    	if (is_object($cont) === true)
    		$this->container = $cont;
    }

    public function hello($name) {
<<<<<<< HEAD
        return 'Bonjour, '.$name;
    }
    
    public function login($username, $passwd) {
		/*
    	$csrf_token = $this->container->get('form.csrf_provider')->generateCsrfToken('authenticate');
    	$now = new \DateTime();
    	
    	if( $autologinToken->getExpiresAt() != NULL && $autologinToken->getExpiresAt() < $now ){
    		return array("csrf_token" => $csrf_token, "last_username" => "", "nextUrl" => $autologinToken->getUrl());
    	}
    	
    	if( $autologinToken->getUser()->getAutologinToken() != $userHash ){
    		return array("csrf_token" => $csrf_token, "last_username" => "", "error" => "Sorry! There is a problem with your link. Please contact support.");
    	}
    	
    	$user = $autologinToken->getUser();
    	
    	$providerKey = $this->container->getParameter('fos_user.firewall_name');
    	$token = new UsernamePasswordToken($user, null, $providerKey, $user->getRoles());
    	$this->get('security.context')->setToken($token);
    	
    	$event = new InteractiveLoginEvent($request, $token);
    	$this->get("event_dispatcher")->dispatch("security.authentication", $event);
		*/
=======
    	$utilisateur = $this->container->get('security.context')->getToken()->getUser();
    	
    	if ($utilisateur->getRoles()[0] === 'IS_AUTHENTICATED_ANONYMOUSLY')
        	return 'Bonjour, '.$name;
        	
    	return 'KO'; // TODO return soap fault
    }
    
    /**
     * 
     * @param unknown $username
     * @param unknown $passwd
     * @return string
     */
    public function login($username, $passwd) {
    	$dm = $this->get('doctrine.odm.mongodb.document_manager');
    	
	    $repo = $dm->getRepository('AcmeUserBundle:User');
	    $user = $repo->findOneByUsername($username);
	
	    if (!$user) {
	        throw $this->createNotFoundException('No demouser found!');
	    }
	
	    $token = new UsernamePasswordToken($user, $passwd, 'main', $user->getRoles());
	
	    $context = $this->get('security.context');
	    $context->setToken($token);
	
	    $router = $this->get('router');
	    $url = $router->generate('dashboard_show');
    	
    	return "";
>>>>>>> 9de106223a11f576de02ae75c35f52c5d8c5227f
    }
}