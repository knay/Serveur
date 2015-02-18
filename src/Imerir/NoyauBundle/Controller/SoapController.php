<?php

namespace Imerir\NoyauBundle\Controller;

use BeSimple\SoapServer\Exception\SenderSoapFault;
use BeSimple\SoapServer\SoapServer;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use BeSimple\SoapBundle\ServiceDefinition\Annotation as Soap;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class SoapController extends ContainerAware
{
	/**
	 * @Soap\Method("login")
	 * @Soap\Param("username",phpType="string")
	 * @Soap\Param("passwd",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function loginAction($username, $passwd) {
		//TODO securité SQL

		//recupere la classe Utilisateur mappé à la table User dans la base de données
		$dm = $this->container->get('doctrine')->getEntityManager();

		//on récupère l'encoder du password dans la base de données pour ensuite hasher le mot de passe et tester
		//si le mot de passe est le même
		$userManager = $this->container->get('fos_user.user_manager');
		$user = $userManager->loadUserByUsername($username);
		$encoder = $this->container->get('security.encoder_factory')->getEncoder($user);
		$hash = $encoder->encodePassword($passwd, $user->getSalt());

		//DEBUG
		//echo $hash;

		//DQL langage doctrine les paramètres sont mis dans un tableau
		$sql = "SELECT u FROM ImerirNoyauBundle:Utilisateur u WHERE u.username = :username AND u.password = :passwd";
		$queryUser = $dm->createQuery($sql)->setParameters(array('username'=>$username,'passwd'=>$hash));

		//on récupère toutes les lignes de la requête
		$users = $queryUser->getResult();
		//on teste si il y a bien un utilisateur username avec le mot de passe passwd
		if(!empty($users)){
			//on lit la première lignes
			$u = $users[0];

			$token = new UsernamePasswordToken($u->getUsername(), $u->getPassword(), 'main', $u->getRoles());
			$context = $this->container->get('security.context');
			$context->setToken($token);
			//TODO controler le phpsessid
			$retourJson = array('token'=>$this->container->get('request')->cookies->get('PHPSESSID'),
				'username'=>$username,
				'role'=>$u->getRoles()[0]);
			return json_encode($retourJson);
		}
		else{
			return new SoapFault("Server","Vos identifiants de connexion sont invalides");
		}
	}
	
	/**
	 * @Soap\Method("ajoutLigneProduit")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutLigneProduitAction($nom){
		try{
			$pdo = new \PDO('mysql:host=localhost;dbname=alba', 'alba', 'alba');
			//on verifie si il y a deja la ligne produit
			$sql = "SELECT * FROM ligne_produit WHERE nom='".$nom."'";

			$resultat = $pdo->query($sql);
			//si la ligne produit n'existe pas
			if($resultat->rowCount()==0){
				$sql = "INSERT INTO ligne_produit(nom)VALUES('".$nom."');";
				$pdo->query($sql);

				return "OK";
			}
			//sinon soapfault
			else{
				return new SoapFault("Server","Echec de l'enregistrement");
			}

		}
		catch(Exception $e){
			// TODO ETIENNE : Ne pas écrire sur la sortir standard. Tu casserais le XML produit par SOAP
			echo 'Erreur : '.$e->getMessage().'<br />';
			echo 'N° : '.$e->getCode();
			return new SoapFault("Server","la ligne produit existe déjà");
		}
	}
	
	/**
	 * Permet de récupérer une ligne de produit.
	 * @param $count Le nombre d'enregistrement voulu, 0 pour tout avoir
	 * @param $offset Le décalage par rapport au début des enregistrements
	 * @param $nom Si vous voulez une ligne de produit spécifique (interet ?).
	 * 
	 * TODO ajouter ASC ou DESC
	 * 
	 * @Soap\Method("getLigneProduit")
	 * @Soap\Param("count",phpType="int")
	 * @Soap\Param("offset",phpType="int")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getLigneProduitAction($count, $offset, $nom) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new SoapFault('Server','[LP001] Vous n\'avez pas les droits nécessaires.');
		
		if(!is_string($nom) || !is_int($offset) || !is_int($count)) // Vérif des arguments
			return new SoapFault('Server','[LP002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service 
		$result = array();

		// Formation de la requete SQL
		$sql = 'SELECT nom FROM ligne_produit ';
		if (!empty($nom))
			$sql.='WHERE nom=\''.$pdo->quote($nom).'\' ';
		if($offset != 0) {
			$sql.='LIMIT '.(int)$offset;
			if ($count != 0)
				$sql.=','.(int)$count;
		}
		
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			array_push($result, $row['nom']);
		}
		
		return json_encode($result);
	}
}
