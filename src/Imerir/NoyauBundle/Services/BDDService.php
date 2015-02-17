<?php 

namespace Imerir\NoyauBundle\Services;

/**
 * Classe permettant de gérer la connexion au serveur de base de données.
 * Cette classe ne considère l'utilisation que de mysql comme SGBD.
 * 
 * L'interet de ce service est uniquement pour que tous les appels à la base
 * de données utilise le même objet PDO.
 */
class BDDService
{
	private $container = null; // Le container symfony (pour récupérer des données dans les fichiers de conf)
	private $pdoConn = null;   // L'objet PDO
	
	/**
	 * Constructeur de la classe. 
	 * Va lire les informations suivantes dans app/config/parameters.yml : 
	 *    - database_host
	 *    - database_port (optionnel)
	 *    - database_name 
	 *    - database_user
	 *    - database_password
	 * Pour le bon fonctionnement du service, il faut s'assurer que ces variables sont bien
	 * définies.
	 * 
	 * @param unknown $cont Le container de symfony, pour récupérer des informations dans les fichiers de config. 
	 */
    public function __construct($container) {
    	if (is_object($container) === true)
    		$this->container = $container;
    	
    	// TODO verifier si des params sont null...
		$dbhost = $this->container->getParameter('database_host');
		$dbport = $this->container->getParameter('database_port');
		$dbname = $this->container->getParameter('database_name');
		$dbuser = $this->container->getParameter('database_user');
		$dbpasswd = $this->container->getParameter('database_password');
		
		if ($dbport !== null)
			$dsn = 'mysql:host='.$dbhost.';port='.$dbport.';dbname='.$dbname;
		else
			$dsn = 'mysql:host='.$dbhost.';dbname='.$dbname;
		
		$this->pdoConn = new \PDO($dsn, $dbuser, $dbpasswd);
    }
    
    /**
     * Renvoie l'objet PDO pour pouvoir travailler avec.
     * @return L'objet PDO.
     */
    public function getPDO() {
    	return $this->pdoConn;
    }
}