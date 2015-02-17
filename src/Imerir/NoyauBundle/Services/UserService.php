<?php 

namespace Imerir\NoyauBundle\Services;

/**
 * Ce service permet de gérer l'utilisateur depuis les fonctions SOAP.
 * Elle permet surtout de voir si l'utilisateur a les droits pour accéder a une
 * fonction SOAP.
 */
class UserService
{
	private $container = null; // Le container symfony (pour récupérer des données dans les fichiers de conf)
	private $user = null; // L'utilisateur connecté
	
	/**
	 * Constructeur de la classe. 
	 * Récupère l'utilisateur depuis le service de sécurité.
	 * 
	 * @param unknown $cont Le container de symfony, pour récupérer des informations dans les fichiers de config. 
	 */
    public function __construct($container) {
    	if (is_object($container) === true)
    		$this->container = $container;
    	
    	$userManager = $this->container->get('fos_user.user_manager');
		$this->user = $userManager->findUserByUsername($this->container->get('security.context')
				->getToken()
				->getUser());
    }
    
    /**
     * Permet de savoir si l'utilisateur a les droits pour accéder a la fonction
     * 
     * @param $role Le rôle pour lequel vous voulez voir si l'utilisateur à le droit.
     * @return True si l'utilisateur a le bon le role, false sinon.
     */
    public function isOk($role) {
    	if ($this->user == null)
    		return false;
    	
    	if (in_array($role, $this->user->getRoles()))
    		return true;
    	
    	return false;
    }
}