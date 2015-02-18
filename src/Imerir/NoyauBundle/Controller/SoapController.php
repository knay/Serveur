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
	 * Permet d'ajouter ou modifier une ligne produit
	 * @param $nom Le nom de la ligne produit a créer ou modifier
	 *
	 * @Soap\Method("ajoutLigneProduit")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutLigneProduitAction($nom){
		//on teste si l'utilisateur a les droits pour accéder à cette fonction
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new SoapFault('Server','[LP001] Vous n\'avez pas les droits nécessaires.');
		if(!is_string($nom)) // Vérif des arguments
			return new SoapFault('Server','[LP002] Paramètres invalides.');
		try {
			$pdo = $this->container->get('bdd_service')->getPdo();
			//on verifie si il y a deja la ligne produit
			$sql = "SELECT * FROM ligne_produit WHERE nom='" . $nom . "'";

			$resultat = $pdo->query($sql);
			//si la ligne produit n'existe pas
			if ($resultat->rowCount() == 0) {
				$sql = "INSERT INTO ligne_produit(nom)VALUES('" . $nom . "');";
				$pdo->query($sql);

				return "OK";
			} //sinon soapfault
			else {
				return new SoapFault("Server", "Echec de l'enregistrement");
			}
		} catch (Exception $e) {
			return new SoapFault("Server", "la ligne produit existe déjà");
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
			$sql.='WHERE nom='.$pdo->quote($nom).' ';
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
	
	/**
	 * Permet d'enregistrer un nouveau attribut, ou de modifier un attribut ainsi que ces valeurs d'attributs.
	 * @param $nom Le nom de l'attribut
	 * @param $ligneProduits Les lignes produits concernée par l'attribut
	 * @param $attributs Les valeurs d'attribut possible
	 * @param $id L'id de l'attribut en cas de modification (mettre 0 en cas d'ajout)
	 *
	 * @Soap\Method("setAttribut")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("lignesProduits",phpType="string")
	 * @Soap\Param("attributs",phpType="string")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function setAttributAction($nom, $lignesProduits, $attributs, $id) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new SoapFault('Server','[SA001] Vous n\'avez pas les droits nécessaires.');
	
		if(!is_string($nom) || !is_string($lignesProduits) || !is_string($attributs) || !is_int($id)) // Vérif des arguments
			return new SoapFault('Server','[SA002] Paramètres invalides.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		
		$tabLgProduit = json_decode($lignesProduits);
		$tabAttributs = json_decode($attributs);
		if ($tabLgProduit === NULL || $tabAttributs === NULL) // On vérif qu'on arrive à decoder le json 
			return new SoapFault('Server','[SA003] Paramètres invalides, JSON attendu.');
	
		// Si on veut modifier un attribut existant
		if ($id !== 0) {
			// Formation de la requete SQL
			$sql = 'SELECT nom FROM attribut WHERE id=\''.(int)$id.'\'';
			$resultat = $pdo->query($sql);
			// Si l'attribut n'existe pas
			if($resultat->rowCount() === 0) {
				// TODO GERER LE CAS DE LA MODIF
			}
		}
		else { // On ajoute un attribut
			//TODO VERIFIER SI L'ATTRIBUT EXISTE DEJA, si oui erreur
			$sql = 'INSERT INTO attribut (nom) VALUES ('.$pdo->quote($nom).')';
			$count = $pdo->exec($sql);
			if ($count !== 1) { // Si problème insertion
				return new SoapFault('Server','[SA004] Erreur lors de l\'enregistrement des données');
			}
			$idAttribut = $pdo->lastInsertId(); // On recup l'id de l'attribut créé
			
			// Insertion des valeurs d'attribut possible
			foreach ($tabAttributs as $libelle) {
				$sql = 'INSERT INTO valeur_attribut (ref_attribut, libelle) VALUES ('.$idAttribut.', '.$pdo->quote($libelle).')';
				$count = $pdo->exec($sql);
			}
			
			// Insertion des lignes produits dans la table ligne_produit_a_pour_attribut
			foreach ($tabLgProduit as $produit) {
				$sql = 'SELECT id FROM ligne_produit WHERE nom='.$pdo->quote($produit);
				$resultat = $pdo->query($sql);
				if($resultat->rowCount() === 0) // Si pas de résultat, la ligne produit n'existe pas et on continue 
					continue;
				
				// On récup l'id de la ligne produit
				foreach  ($resultat as $row) {
					$idLigneProduit = $row['id'];
				}
				
				$sql = 'INSERT INTO ligne_produit_a_pour_attribut (ref_ligne_produit, ref_attribut)' .
						'VALUES ('.$idLigneProduit.', '.$idAttribut.')'; 
				$count = $pdo->exec($sql);
			}
		}
	
		return $count;
	}
	
	/**
	 * Permet d'enregistrer un nouveau attribut, ou de modifier un attribut ainsi que ces valeurs d'attributs.
	 * @param $nom Le nom de l'attribut
	 * @param $ligneProduits Les lignes produits concernée par l'attribut
	 * @param $attributs Les valeurs d'attribut possible
	 * @param $id L'id de l'attribut en cas de modification (mettre 0 en cas d'ajout)
	 *
	 * @Soap\Method("getAttribut")
	 * @Soap\Param("idLigneProduit",phpType="int")
	 * @Soap\Param("idAttribut",phpType="int")
	 * @Soap\Param("avecValeurAttribut",phpType="boolean")
	 * @Soap\Param("avecLigneProduit",phpType="boolean")
	 * @Soap\Result(phpType = "string")
	 */
	public function getAttributAction($idLigneProduit, $idAttribut, $avecValeurAttribut, $avecLigneProduit) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new SoapFault('Server','[GA001] Vous n\'avez pas les droits nécessaires.');
	
		if(!is_int($idLigneProduit) || !is_int($idAttribut) || !is_bool($avecValeurAttribut) || !is_bool($avecLigneProduit)) // Vérif des arguments
			return new SoapFault('Server','[GA002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo();
		$result = array();
		
		// Si on a pas de critère c'est qu'on veut tout les attributs et on ne va pas récupérer les valeurs ni les lignes produits
		if ($idLigneProduit === 0 && $idAttribut === 0) {
			$sql = 'SELECT nom FROM attribut';
			foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
				array_push($result, $row['nom']);
			}
		}
		else if ($idLigneProduit !== 0) {
			$sql = 'SELECT a.nom FROM attribut a ';
			if ($avecValeurAttribut)
				$sql .= 'JOIN valeur_attribut v ON v.ref_attribut=a.id ';
			
			$sql.='WHERE a.id='.(int)$idLigneProduit;
			//if ($avecLigneProduit)
				
			$result = $pdo->query($sql);
		}
		
		return json_encode($result);
	}
	
	/**
	 * Permet d'ajouter ou modifier un produit
	 * @param $nom Le nom du produit a créer ou modifier
	 * @param $ligneProduit Le nom de la ligne produit pour lequelle le produit est créé
	 *
	 * @Soap\Method("ajoutProduit")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("ligneProduit",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutProduitAction($nom,$ligneProduit){
		//on teste si l'utilisateur a les droits pour accéder à cette fonction
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new SoapFault('Server','[LP001] Vous n\'avez pas les droits nécessaires.');
		if(!is_string($nom) || !is_string($ligneProduit)) // Vérif des arguments
			return new SoapFault('Server','[LP002] Paramètres invalides.');

		try {

			$pdo = $this->container->get('bdd_service')->getPdo();

			//on verifie si il y a deja le produit
			$sql = "SELECT * FROM produit JOIN ligne_produit ON produit.ref_ligne_produit = ligne_produit.id
			WHERE produit.nom=" .$pdo->quote($nom). " AND ligne_produit.nom=".$pdo->quote($ligneProduit)."";

			//requête qui permet de récupérer l'identifiant de la ligne produit
			$sql_lp = "SELECT * FROM ligne_produit WHERE nom=".$pdo->quote($ligneProduit)."";

			//on exécute les requêtes
			$resultat = $pdo->query($sql);

			if($resultat->rowCount() == 0) {

				//on recupère l'identifiant de la ligne produit
				foreach ($pdo->query($sql_lp) as $row) {
					$id = $row["id"];
				}
				//on insert le produit pour la ligne de produit $ligneProduit
				$sql = "INSERT INTO produit(nom,ref_ligne_produit)VALUES('" . $nom . "','".$id."');";
				$pdo->query($sql);

				return "OK";
			}

		} catch (Exception $e) {
			return new SoapFault("Server", "la ligne produit existe déjà");
		}
	}

	/**
	 * Permet d'ajouter ou modifier un produit
	 * @param $nom Le nom du produit a créer ou modifier
	 * @param $ligneProduit Le nom de la ligne produit pour lequelle le produit est créé
	 *
	 * @Soap\Method("getProduit")
	 * @Soap\Param("count",phpType="int")
	 * @Soap\Param("offset",phpType="int")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("ligneproduit",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getProduitAction($count, $offset, $nom, $ligneproduit){
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new SoapFault('Server','[LP001] Vous n\'avez pas les droits nécessaires.');

		if(!is_string($nom) || !is_int($offset) || !is_int($count) || !is_string($ligneproduit)) // Vérif des arguments
			return new SoapFault('Server','[LP002] Paramètres invalides.');

		//on récupere l'objet pdo connecté à la base du logiciel
		$pdo = $this->container->get('bdd_service')->getPdo();

		// Formation de la requete SQL selon les paramètres donnés
		$sql = 'SELECT ligne_produit.nom, produit.nom FROM produit JOIN ligne_produit ON produit.ref_ligne_produit=ligne_produit.id ';
		if (!empty($nom) && !empty($ligneproduit))
			$sql.='WHERE produit.nom='.$pdo->quote($nom).' AND ligne_produit.nom='.$pdo->quote($ligneproduit).'';
		elseif (empty($nom) && !empty($ligneproduit))
			$sql.='WHERE ligne_produit.nom='.$pdo->quote($ligneproduit).'';
		elseif (!empty($nom) && empty($ligneproduit))
			$sql.='WHERE produit.nom='.$pdo->quote($nom).'';
		if($offset != 0) {
			$sql.='LIMIT '.(int)$offset;
			if ($count != 0)
				$sql.=','.(int)$count;
		}

		//exécution de la requête
		$resultat = array();

		//on créé le tableau de retour à partir de la requête
		foreach ($pdo->query($sql) as $ligne) {
			array_push($resultat, $ligne['ligne_produit.nom']);
			array_push($resultat,$ligne['produit.nom']);
		}

		//encodage json du tableau de résultat avec ligneproduit et produit
		return json_encode($resultat);
	}
}
