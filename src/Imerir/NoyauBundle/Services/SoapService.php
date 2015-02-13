<?php namespace Imerir\NoyauBundle\Services;

class SoapService
{
	private $container;
	
    public function __construct($cont) {
    	if (is_object($cont) === true)
    		$this->container = $cont;
    }

    public function hello($name) {
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
    }
}