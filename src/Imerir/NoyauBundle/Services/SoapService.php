<?php namespace Imerir\NoyauBundle\Services;

class SoapService
{
	private $container;
	
    public function __construct($cont) {
    	if (is_object($cont) === true)
    		$this->container = $cont;
    }

    public function hello($name) {
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
    }
}