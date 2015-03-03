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

use Imerir\NoyauBundle\Entity\Utilisateur;

/**
 * Classe utilisant BeSimple SoapBundle afin de proposer un serveur SOAP.
 * Le wsdl est produit automatiquement en suivant les indications des annotations PHP
 * en en-tête PHP.
 * 
 * Liste des fonction du serveur SOAP : 
 *   login, ajoutLigneProduit, getLigneProduit, setAttribut, getAttribut, ajoutProduit, 
 *   getProduit. 
 */
class SoapController extends ContainerAware
{
	/**
	 * Permet de connecter l'utilisateur côté serveur.
	 * La fonction va chercher en base de données si l'authentification est bonne, si elle
	 * l'est, sera créera une session Symfony, sinon une erreur est levée.
	 * Une fois la connection effectuée, le client doit renvoyer le token fournit sous la 
	 * forme de cookie SOAP sous le nom de PHPSESSID.
	 * 
	 * @param $username Le nom de l'utilisateur à connecter.
	 * @param $passwd Le mot de passe de l'utilisateur.
	 * 
	 * @Soap\Method("login")
	 * @Soap\Param("username",phpType="string")
	 * @Soap\Param("passwd",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function loginAction($username, $passwd) {
		//recupere la classe Utilisateur mappé à la table User dans la base de données
		$dm = $this->container->get('doctrine')->getEntityManager();
		
		try { // Test de la connexion au serveur SQL
			$dm->getConnection()->connect();
		} catch (\Exception $e) {
			return new \SoapFault('Server', '[LOG001] Problème de connexion au serveur de base de données.');
		}

		// Avant toute chose on regarde si l'utilisateur existe... Sinon on continue pas
		$sql = "SELECT u FROM ImerirNoyauBundle:Utilisateur u WHERE u.username = :username";
		$queryUser = $dm->createQuery($sql)->setParameters(array('username' => $username));
		$users = $queryUser->getResult();
		if(count($users) === 0) {
			return new \SoapFault('Server', 'Vos identifiants de connexion sont invalides.');
		}
		
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
		$queryUser = $dm->createQuery($sql)->setParameters(array('username'=>$username, 'passwd'=>$hash));

		//on récupère toutes les lignes de la requête
		$users = $queryUser->getResult();
		//on teste si il y a bien un utilisateur username avec le mot de passe passwd
		if(count($users) !== 0) {
			//on lit la première lignes
			$u = $users[0];

			$token = new UsernamePasswordToken($u->getUsername(), $u->getPassword(), 'main', $u->getRoles());
			$context = $this->container->get('security.context');
			$context->setToken($token);

			$retourJson = array('token'=>$this->container->get('request')->cookies->get('PHPSESSID'),
				'username'=>$username,
				'role'=>$u->getRoles()[0]);
			return json_encode($retourJson);
		}
		else{
			return new \SoapFault('Server', 'Vos identifiants de connexion sont invalides.');
		}
	}
	
	
	/**
	 * Permet d'ajouter ou modifier une ligne produit
	 * @param $nom Le nom de la ligne produit a créer ou modifier
	 *
	 * @Soap\Method("enregistrerAchat")
	 * @Soap\Param("articles",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function enregistrerAchatAction($articles) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[EA001] Vous n\'avez pas les droits nécessaires.');
		if(!is_string($articles)) // Vérif des arguments
			return new \SoapFault('Server','[EA002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo();
		$tabArticles = json_decode($articles);
		
		$sql = 'INSERT INTO facture (date_facture, est_visible) VALUE (NOW(), true)';
		$resultat = $pdo->query($sql);
		$ref_facture = $pdo->lastInsertId();
		
		foreach($tabArticles as $article) {
			$code_barre = $article->codeBarre;
			$quantite = $article->quantite;
			$promo = $article->promo;
			$prix = 0;
			
			$sql = 'SELECT montant_client FROM prix JOIN article ON ref_article=article.id WHERE code_barre='.$pdo->quote($code_barre);
			$resultat = $pdo->query($sql);
			foreach ($resultat as $row) {
				$prix = floatval($row['montant_client']);
			}
			
			$sql = 'INSERT INTO mouvement_stock (ref_article, date_mouvement, quantite_mouvement, est_inventaire, est_visible)
					VALUE ((SELECT id FROM article WHERE code_barre='.$pdo->quote($code_barre).'), 
							NOW(), \''.(int)-$quantite.'\', false, true)';
			
			$resultat = $pdo->query($sql);
			$ref_mvt_stock = $pdo->lastInsertId();
			
			$ref_remise = 0;
			if (0 !== $promo) { // S'il y a une promo on l'enregistre dans la table remise
				$sql = 'INSERT INTO remise (reduction, type_reduction) VALUE ('.(int)$promo.', \'taux\')';
				$resultat = $pdo->query($sql);
				$ref_remise = $pdo->lastInsertId();
			}
			
			if ($ref_remise !== 0)
				$sql = 'INSERT INTO ligne_facture (ref_facture, ref_mvt_stock, ref_remise)
				     	VALUE ('.(int)$ref_facture.', '.(int)$ref_mvt_stock.', '.(int)$ref_remise.')';
			else 
				$sql = 'INSERT INTO ligne_facture (ref_facture, ref_mvt_stock)
				     	VALUE ('.(int)$ref_facture.', '.(int)$ref_mvt_stock.')';
			$resultat = $pdo->query($sql);
		}
		
		return '';
	}
	
	/**
	 * TODO 
	 * @Soap\Method("modifArticle")
	 * @Soap\Param("article",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifArticleAction($article) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[MA001] Vous n\'avez pas les droits nécessaires.');
	
		if(!is_string($article)) // Vérif des arguments
			return new \SoapFault('Server','[MA002] Paramètres invalides.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$tabArticle = json_decode($article);
		$result = array();
		
		$code_barre = $tabArticle->codeBarre;
		$nom_produit = $tabArticle->produit;
		$attributs = $tabArticle->attributs;
		$prix = $tabArticle->prix;
		
		$sql = 'SELECT * FROM article WHERE code_barre='.$pdo->quote($code_barre);
		$resultat = $pdo->query($sql);
		
		if ($resultat->rowCount() == 0) { // Si l'article n'existe pas on l'ajoute
			$sql = 'SELECT id FROM produit WHERE nom='.$pdo->quote($nom_produit); // On récup l'id du produit
			$resultat = $pdo->query($sql);
			
			foreach ($pdo->query($sql) as $row) {
				$idProduit = $row['id'];
			}
			
			$sql = 'INSERT INTO article(ref_produit, code_barre, est_visible) 
				    VALUE (\''.$idProduit.'\', '.$pdo->quote($code_barre).', TRUE)';
			$resultat = $pdo->query($sql);
			$idArticle = $pdo->lastInsertId(); // On récup l'id de l'article créé
		}
		else { // Si l'article existe on recup juste son ID
			foreach ($resultat as $row) {
				$idArticle = $row['id'];
			}
			$sql = 'UPDATE article SET ref_produit=
					(SELECT id FROM produit WHERE nom='.$pdo->quote($nom_produit).') 
				    WHERE article.id = \''.$idArticle.'\'';
			$resultat = $pdo->query($sql);
		}
		
		$sql = 'INSERT INTO prix(ref_article, montant_fournisseur, montant_client, date_modif)
					VALUE (\''.$idArticle.'\', 0, \''.(float)$prix.'\', NOW())';
		$resultat = $pdo->query($sql);
		
		$sql = 'DELETE FROM article_a_pour_val_attribut WHERE ref_article=\''.$idArticle.'\'';
		$resultat = $pdo->query($sql); // On vide la table de correspondance pour cet article
		
		// On parcourt toutes les valeur d'attributs de cette article pour les enregistrer
		foreach ($attributs as $nomAttribut => $libelleValeurAttribut) {
			$sql = 'SELECT valeur_attribut.id AS vaid, attribut.id AS aid FROM valeur_attribut
			        JOIN attribut ON ref_attribut = attribut.id
			        WHERE attribut.nom='.$pdo->quote($nomAttribut).' AND valeur_attribut.libelle='.$pdo->quote($libelleValeurAttribut);
			
			foreach ($pdo->query($sql) as $row) {
				$idValAttribut = $row['vaid'];
			}
			
			$sql = 'INSERT INTO article_a_pour_val_attribut (ref_article, ref_val_attribut)
					VALUE (\''.$idArticle.'\', \''.$idValAttribut.'\')'; // Insertion de la valeur attribut
			$resultat = $pdo->query($sql);
		}
		
		return '';
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
		//coucou
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[ALP001] Vous n\'avez pas les droits nécessaires.');
		if(!is_string($nom)) // Vérif des arguments
			return new \SoapFault('Server','[ALP002] Paramètres invalides.');
		try {
			$pdo = $this->container->get('bdd_service')->getPdo();
			//on verifie si il y a deja la ligne produit
			$sql = 'SELECT * FROM ligne_produit WHERE nom=' .$pdo->quote($nom) .'';

			$resultat = $pdo->query($sql);
			//si la ligne produit n'existe pas
			if ($resultat->rowCount() == 0) {
				$sql = 'INSERT INTO ligne_produit(nom)VALUES(' .$pdo->quote($nom) . ')';
				$pdo->query($sql);

				return "OK";
			} //sinon soapfault
			else {
				return new \SoapFault("Server", "[ALP003] Echec de l'enregistrement");
			}
		} catch (Exception $e) {
			return new \SoapFault("Server", "[ALP004] La ligne produit existe déjà");
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
			return new \SoapFault('Server','[GLP001] Vous n\'avez pas les droits nécessaires.');
		
		if(!is_string($nom) || !is_int($offset) || !is_int($count)) // Vérif des arguments
			return new \SoapFault('Server','[GLP002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service 
		$result = array();

		// Formation de la requete SQL
		$sql = 'SELECT id, nom FROM ligne_produit ';
		if (!empty($nom))
			$sql.='WHERE nom='.$pdo->quote($nom).' ';
		if($offset != 0) {
			$sql.=' ORDER BY nom ASC LIMIT '.(int)$offset;
			if ($count != 0)
				$sql.=','.(int)$count;
		}
		else{
			$sql .=' ORDER BY nom ASC';
		}
		
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('id' => $row['id'], 'nom' => $row['nom']);
			array_push($result, $ligne);
		}
		
		return json_encode($result);
	}

	/**
	 * Permet de modifier une ligne produit.
	 * @param $nom Si vous voulez une ligne de produit spécifique (interet ?).
	 *
	 * @Soap\Method("modifLigneProduit")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifLigneProduitAction($id,$nom) {

		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[MLP001] Vous n\'avez pas les droits nécessaires.');


		if(!is_int($id)) // Vérif des arguments
			return new \SoapFault('Server','[MLP002] Paramètre invalide.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		//$result = array();

		// Formation de la requete SQL
		$sql = 'UPDATE ligne_produit SET nom='.$pdo->quote($nom).' WHERE id='.$pdo->quote($id).' ';

        $pdo->query($sql);
		return "OK";
	}
	
	/**
	 * Permet de faire un inventaire d'articles.
	 * Prend en paramètre un tableau json d'articles au format : 
	 *   -> codeBarre = $codeBarre
	 *   -> produit = $nomProduit
	 *   -> quantite = $quantite
	 *   -> attributs = 
	 *   ----> $nomAttribut1 = $valAttribut1
	 *   ----> $nomAttribut2 = $valAttribut2
	 *   ----> ...
	 * 
	 * @param articles Un chaine de caractère JSON correspondant à un tableau ayant le format décrit ci-dessus.
	 * @param avecPrix Dit qu'on fournit le prix de l'article ou non pendant l'inventaire
	 * 
	 * @Soap\Method("faireInventaire")
	 * @Soap\Param("articles",phpType="string")
	 * @Soap\Param("avecPrix",phpType="boolean")
	 * @Soap\Result(phpType = "string")
	 */
	public function faireInventaireAction($articles, $avecPrix) {
		if (!($this->container->get('user_service')->isOk('ROLE_EMPLOYE')) && 
			!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[INV001] Vous n\'avez pas les droits nécessaires.');
	
		if(!is_string($articles) || !is_bool($avecPrix)) // Vérif des arguments
			return new \SoapFault('Server','[INV002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$tabArticles = json_decode($articles);
		
		foreach($tabArticles as $article) {
			$code_barre = $article->codeBarre;
			$nom_produit = $article->produit;
			$quantite = $article->quantite;
			$attributs = $article->attributs;
			
			if ($avecPrix)
				$prixClient = $article->prix;
			
			if(empty($code_barre)) // Si pas de code barre on enregistre pas c'est pas normal
				break;
			
			$sql = 'SELECT id FROM article WHERE code_barre='.$pdo->quote($code_barre); // On cherche si l'article existe ou non
			$resultat = $pdo->query($sql);
			
			if ($resultat->rowCount() == 0) { // Si l'article n'existe pas on l'ajoute
				$sql = 'SELECT id FROM produit WHERE nom='.$pdo->quote($nom_produit); // On récup l'id du produit
				$resultat = $pdo->query($sql);
				
				foreach ($pdo->query($sql) as $row) {
					$idProduit = $row['id'];
				}
				
				$sql = 'INSERT INTO article(ref_produit, code_barre, est_visible) 
					    VALUE (\''.$idProduit.'\', '.$pdo->quote($code_barre).', TRUE)';
				$resultat = $pdo->query($sql);
				$idArticle = $pdo->lastInsertId(); // On récup l'id de l'article créé
			}
			else { // Si l'article existe on recup juste son ID
				foreach ($resultat as $row) {
					$idArticle = $row['id'];
				}
				$sql = 'UPDATE article SET ref_produit=
						(SELECT id FROM produit WHERE nom='.$pdo->quote($nom_produit).') 
					    WHERE article.id = \''.$idArticle.'\'';
				$resultat = $pdo->query($sql);
			}
			
			if ($avecPrix) { // Si on enregistre le prix avec
				$sql = 'INSERT INTO prix(ref_article, montant_fournisseur, montant_client, date_modif)
							VALUE (\''.$idArticle.'\', 0, \''.(float)$prixClient.'\', NOW())';
				$resultat = $pdo->query($sql);
			}
			
			$sql = 'INSERT INTO mouvement_stock (ref_article, quantite_mouvement, date_mouvement, est_inventaire)
					VALUES (\''.$idArticle.'\', '.$quantite.', NOW(), TRUE)'; // Insertion du mouvement de stock
			$resultat = $pdo->query($sql);
			
			$sql = 'DELETE FROM article_a_pour_val_attribut WHERE ref_article=\''.$idArticle.'\'';
			$resultat = $pdo->query($sql); // On vide la table de correspondance pour cet article
			
			// On parcourt toutes les valeur d'attributs de cette article pour les enregistrer
			foreach ($attributs as $nomAttribut => $libelleValeurAttribut) {
				$sql = 'SELECT valeur_attribut.id AS vaid, attribut.id AS aid FROM valeur_attribut
				        JOIN attribut ON ref_attribut = attribut.id
				        WHERE attribut.nom='.$pdo->quote($nomAttribut).' AND valeur_attribut.libelle='.$pdo->quote($libelleValeurAttribut);
				
				foreach ($pdo->query($sql) as $row) {
					$idValAttribut = $row['vaid'];
				}
				
				$sql = 'INSERT INTO article_a_pour_val_attribut (ref_article, ref_val_attribut)
						VALUE (\''.$idArticle.'\', \''.$idValAttribut.'\')'; // Insertion de la valeur attribut
				$resultat = $pdo->query($sql);
			}
		}
		
		return '';
	}
	
	
	/**
	 * Permet de récupérer un article ainsi que ces attributs et son produit référent
	 * à partir de son code barre.
	 * Renvoie une reponse json sous la forme :
	 *    -> nomProduit = $nom
	 *    -> attributs =
	 *    ----> attribut1 = $valAttribut1
	 *    ----> attribut2 = $valAttribut2
	 *    
	 * @param $codeBarre Le code barre de l'article recherché.
	 * 
	 * @Soap\Method("getArticleFromCodeBarre")
	 * @Soap\Param("codeBarre",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getArticleFromCodeBarreAction($codeBarre) {
		if (!($this->container->get('user_service')->isOk('ROLE_EMPLOYE')) &&
				!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GAFCB001] Vous n\'avez pas les droits nécessaires.');
		
		if(!is_string($codeBarre)) // Vérif des arguments
			return new \SoapFault('Server','[GAFCB002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$reponse = array();
		
		$sql = 'SELECT produit.nom AS nomProduit, attribut.nom AS nomAttribut, valeur_attribut.libelle AS nomValAttribut
				FROM article
				JOIN produit ON article.ref_produit = produit.id
				JOIN article_a_pour_val_attribut ON article_a_pour_val_attribut.ref_article = article.id
				JOIN valeur_attribut ON article_a_pour_val_attribut.ref_val_attribut = valeur_attribut.id
				JOIN attribut ON valeur_attribut.ref_attribut = attribut.id
				WHERE article.code_barre = '.$pdo->quote($codeBarre);
		$resultat = $pdo->query($sql);
		
		$reponse['attributs'] = array();
		foreach ($resultat as $row) {
			$reponse['nomProduit'] = $row['nomProduit'];
			$reponse['attributs'][$row['nomAttribut']] = $row['nomValAttribut'];
		}
		
		return json_encode($reponse);
	}

	/**
	 * Permet de récupèrer les attributs d'un produit suivant son nom.
	 * @param $nom Le nom du produit dont on chercher les attributs.
	 * 
	 * @Soap\Method("getAttributFromNomProduit")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getAttributFromNomProduitAction($nom) {
		if (!($this->container->get('user_service')->isOk('ROLE_EMPLOYE')) && 
			!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GAFNP001] Vous n\'avez pas les droits nécessaires.');
	
		if(!is_string($nom)) // Vérif des arguments
			return new \SoapFault('Server','[GAFNP002] Paramètres invalides.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
		$tabValAttribut = array();
	
		// Formation de la requete SQL
		$sql = 'SELECT att_nom AS nom, libelle, est_visible FROM (
				SELECT produit.nom, attribut.nom AS "att_nom", valeur_attribut.libelle, valeur_attribut.est_visible
				FROM produit 
				JOIN ligne_produit ON ligne_produit.id = produit.ref_ligne_produit
				JOIN ligne_produit_a_pour_attribut ON ligne_produit_a_pour_attribut.ref_ligne_produit = ligne_produit.id
				JOIN attribut ON attribut.id=ligne_produit_a_pour_attribut.ref_attribut
				JOIN valeur_attribut ON attribut.id = valeur_attribut.ref_attribut
				)t
				WHERE est_visible = TRUE AND nom='.$pdo->quote($nom).
			   'GROUP BY att_nom, libelle';
	
		$dernierNomAttribut = '';
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			if ($dernierNomAttribut === '') { // Premier tour de boucle, on a pas encore de nom d'attribut
				$dernierNomAttribut = $row['nom'];
			}
			
			if ($row['nom'] !== $dernierNomAttribut) { // Si on change de nom d'attribut, on a fini de travailler avec ses valeurs donc on push
				array_push($result, array('nom'=>$dernierNomAttribut, 'valeurs'=>$tabValAttribut));
				$tabValAttribut = array();
				$dernierNomAttribut = $row['nom'];
			}
			array_push($tabValAttribut, $row['libelle']);
		}
		array_push($result, array('nom'=>$dernierNomAttribut, 'valeurs'=>$tabValAttribut));
						
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
			return new \SoapFault('Server','[SA001] Vous n\'avez pas les droits nécessaires.');
	
		if(!is_string($nom) || !is_string($lignesProduits) || !is_string($attributs) || !is_int($id)) // Vérif des arguments
			return new \SoapFault('Server','[SA002] Paramètres invalides.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		
		$tabLgProduit = json_decode($lignesProduits);
		$tabAttributs = json_decode($attributs);
		if ($tabLgProduit === NULL || $tabAttributs === NULL || empty($nom)) // On vérif qu'on arrive à decoder le json 
			return new \SoapFault('Server','[SA003] Paramètres invalides, JSON attendu.');
	
		// Si on veut modifier un attribut existant
		if ($id !== 0) {
			// Formation de la requete SQL
			$sql = 'SELECT nom FROM attribut WHERE id=\''.(int)$id.'\'';
			$count = $pdo->query($sql);
			
			// Si l'attribut n'existe pas
			if($count->rowCount() === 0) {
				return new \SoapFault('Server','[SA004] L\'attribut choisi n\'existe pas. Peut-être vouliez-vous ajouter un attribut ?');			
			}
			
			$sql = 'UPDATE attribut SET nom='.$pdo->quote($nom).' WHERE id=\''.(int)$id.'\''; // On modifie le nom de l'attribut
			$count = $pdo->query($sql);
			
			$sql = 'UPDATE valeur_attribut SET est_visible=FALSE WHERE ref_attribut=\''.(int)$id.'\' AND est_visible=TRUE'; // On supprime toutes les valeurs de cet attribut
			$count = $pdo->query($sql);
			
			// Insertion des valeurs d'attribut possible
			foreach ($tabAttributs as $libelle) {
				if (!empty($libelle)) {
					$sql = 'INSERT INTO valeur_attribut (ref_attribut, libelle, est_visible) VALUES (\''.(int)$id.'\', '.$pdo->quote($libelle).', TRUE)';
					$count = $pdo->exec($sql);
				}
			}
			
			$sql = 'DELETE FROM ligne_produit_a_pour_attribut WHERE ref_attribut=\''.(int)$id.'\''; // On supprime toutes les valeurs de cet attribut
			$count = $pdo->query($sql);
			
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
						'VALUES ('.$pdo->quote($idLigneProduit).', '.$pdo->quote($id).')';
				$count = $pdo->exec($sql);
			}
		}
		else { // On ajoute un attribut
			$sql = 'SELECT nom FROM attribut WHERE nom='.$pdo->quote($nom);
			$resultat = $pdo->query($sql);
			if($resultat->rowCount() !== 0)
				return new \SoapFault('Server','[SA005] Le nom que vous avez choisi existe déjà. Peut-être vouliez-vous modifier un attribut existant ?');
				
			$sql = 'INSERT INTO attribut (nom, est_visible) VALUES ('.$pdo->quote($nom).', TRUE)';
			$count = $pdo->exec($sql);
			if ($count !== 1) { // Si problème insertion
				return new \SoapFault('Server','[SA006] Erreur lors de l\'enregistrement des données');
			}
			$idAttribut = $pdo->lastInsertId(); // On recup l'id de l'attribut créé
			
			// Insertion des valeurs d'attribut possible
			foreach ($tabAttributs as $libelle) {
				$sql = 'INSERT INTO valeur_attribut (ref_attribut, libelle, est_visible) VALUES ('.$idAttribut.', '.$pdo->quote($libelle).', TRUE)';
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
						'VALUES ('.$pdo->quote($idLigneProduit).', '.$pdo->quote($idAttribut).')';
				$count = $pdo->exec($sql);
			}
		}
	
		return $count;
	}
	
	/**
	 * Permet d'enregistrer un nouveau attribut, ou de modifier un attribut ainsi que ces valeurs d'attributs.
	 * Le résultat produit sera un tableau JSON de la forme : 
	 *    -> nom
	 *    -> id
	 *    -> attributs (tableau)
	 *    ----> valeurAttr
	 *    -> lignes produit (tableau)
	 *    ----> nomLigneProduit
	 *    
	 * @param $idLigneProduit L'id de la ligne produit dont on veut les attributs (0 pour tous les avoir)
	 * @param $idAttribut Les lignes produits concernée par l'attribut
	 * @param $avecValeurAttribut True si vous voulez récupèrer les valeur d'attributs possible
	 * @param $avecLigneProduit True si vous voulez récupérer les lignes produit liées à l'attribut
	 *
	 * @Soap\Method("getAttribut")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("idLigneProduit",phpType="int")
	 * @Soap\Param("idAttribut",phpType="int")
	 * @Soap\Param("avecValeurAttribut",phpType="boolean")
	 * @Soap\Param("avecLigneProduit",phpType="boolean")
	 * @Soap\Result(phpType = "string")
	 */
	public function getAttributAction($nom, $idLigneProduit, $idAttribut, $avecValeurAttribut, $avecLigneProduit) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GA001] Vous n\'avez pas les droits nécessaires.');
	
		if(!is_int($idLigneProduit) || !is_int($idAttribut) || !is_bool($avecValeurAttribut) 
		|| !is_bool($avecLigneProduit) || !is_string($nom)) // Vérif des arguments
			return new \SoapFault('Server','[GA002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo();
		$result = array(); // Tableau contenant le résultat
		
		// Si on a pas de critère c'est qu'on veut tout les attributs et on ne va pas récupérer les valeurs ni les lignes produits
		if ($idLigneProduit === 0 && $idAttribut === 0) {
			if (true === $avecValeurAttribut) {
				$sql = 'SELECT a.id AS aid, nom, v.libelle, a.est_visible FROM attribut a
				        JOIN valeur_attribut v ON v.ref_attribut=a.id 
						WHERE a.est_visible = TRUE AND v.est_visible = TRUE ';
				
				if (!empty($nom)) {
					$nom = '%'.$nom.'%';
					$sql.=' AND a.nom LIKE '.$pdo->quote($nom);
				}
				
				$sql.= ' GROUP BY libelle ORDER BY nom ASC';
				
				$tabAttributs = array();
				$dernierNom = '';
				$dernierId = 0;
				foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
					if ($dernierNom !== $row['nom']) {
						$ligne = array('id'=>$dernierId, 'nom'=>$dernierNom, 'attributs'=>$tabAttributs);
						array_push($result, $ligne);
						$tabAttributs = array();
					}
					$dernierLibelle = $row['libelle'];
					$dernierNom = $row['nom'];
					$dernierId = $row['aid'];
					array_push($tabAttributs, $row['libelle']);
				}
				$ligne = array('id'=>$dernierId, 'nom'=>$dernierNom, 'attributs'=>$tabAttributs);
				array_push($result, $ligne);
			}
			else {
				$sql = 'SELECT id, nom FROM attribut a ';

				if (!empty($nom)) {
					$nom = '%'.$nom.'%';
					$sql.='WHERE attribut.est_visible = TRUE AND a.nom LIKE '.$pdo->quote($nom);
				}
				foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
					$ligne = array('id'=>$row['id'], 'nom'=>$row['nom']);
					array_push($result, $ligne);
				}
			}
		}
		// Si on cherche un attribut avec un id spécifique
		else if ($idAttribut !== 0) {
			if (true === $avecValeurAttribut) { // Si on veut les valeurs d'attributs, on les récupère
				$sql = 'SELECT a.id, a.nom, v.libelle FROM attribut a ';
				$sql.= 'JOIN valeur_attribut v ON v.ref_attribut=a.id ';
				$sql.= 'WHERE v.est_visible = TRUE AND a.id='.(int)$idAttribut;
				
				$result['attribut'] = array();
				foreach ($pdo->query($sql) as $row) { // On ajoute tous les attributs à la réponse
					$result['nom'] = $row['nom'];
					$result['id'] = $row['id'];
					$ligne = array('valeurAttr'=>$row['libelle']);
					array_push($result['attribut'], $ligne);
				}
			}
			if (true === $avecLigneProduit) { // Si on veut les lignes produits, on les récupère
				$sql = 'SELECT l.nom AS nomLigneProduit FROM attribut a ';
				$sql.= 'JOIN ligne_produit_a_pour_attribut lp ON a.id=lp.ref_attribut ';
				$sql.= 'JOIN ligne_produit l ON ref_ligne_produit=l.id ';
				$sql.= 'WHERE a.id='.(int)$idAttribut;
				
				$result['ligneProduit'] = array();
				foreach ($pdo->query($sql) as $row) { // On ajoute toutes les lignes produit à la réponse
					$ligne = array('nomLigneProduit'=>$row['nomLigneProduit']);
					array_push($result['ligneProduit'], $ligne);
				}
			}
		}
		// TODO faire la recherche par ligne produit
		return json_encode($result);
	}
	
	/**
	 * Permet de récupérer le prix depuis le code barre.
	 * 
	 * @param $codeBarre Le code barre du produit recherché.
	 * 
	 * @Soap\Method("getPrixFromCodeBarre")
	 * @Soap\Param("codeBarre",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getPrixFromCodeBarreAction($codeBarre) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GPFCB001] Vous n\'avez pas les droits nécessaires.');
		
		if(!is_string($codeBarre)) // Vérif des arguments
			return new \SoapFault('Server','[GPFCB002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo();
		$sql = 'SELECT montant_client FROM prix JOIN article ON ref_article=article.id WHERE code_barre='.$pdo->quote($codeBarre);
		$resultat = $pdo->query($sql);
		
		$prix = 0;
		foreach ($resultat as $row) {
			$prix = $row["montant_client"];
		}
		
		return ''.$prix;
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
			return new \SoapFault('Server','[ALP001] Vous n\'avez pas les droits nécessaires.');
		if(!is_string($nom) || !is_string($ligneProduit)) // Vérif des arguments
			return new \SoapFault('Server','[ALP002] Paramètres invalides.');

		try {

			$pdo = $this->container->get('bdd_service')->getPdo();

			//on verifie si il y a deja le produit
			$sql = 'SELECT * FROM produit JOIN ligne_produit ON produit.ref_ligne_produit = ligne_produit.id
			WHERE produit.nom=' .$pdo->quote($nom). ' AND ligne_produit.nom='.$pdo->quote($ligneProduit).'';

			//requête qui permet de récupérer l'identifiant de la ligne produit
			$sql_lp = 'SELECT * FROM ligne_produit WHERE nom='.$pdo->quote($ligneProduit).'';

			//on exécute les requêtes
			$resultat = $pdo->query($sql);

			if($resultat->rowCount() == 0) {

				//on recupère l'identifiant de la ligne produit
				foreach ($pdo->query($sql_lp) as $row) {
					$id = $row["id"];
				}
				//on insert le produit pour la ligne de produit $ligneProduit
				$sql = 'INSERT INTO produit(nom,ref_ligne_produit)VALUES(' .$pdo->quote($nom) . ','.$pdo->quote($id).');';
				$pdo->query($sql);

				return "OK";
			}

		} catch (Exception $e) {
			return new \SoapFault("Server", "[ALP003] La ligne produit existe déjà");
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
			return new \SoapFault('Server','[GP001] Vous n\'avez pas les droits nécessaires.');

		if(!is_string($nom) || !is_int($offset) || !is_int($count) || !is_string($ligneproduit)) // Vérif des arguments
			return new \SoapFault('Server','[GP002] Paramètres invalides.');

		//on récupere l'objet pdo connecté à la base du logiciel
		$pdo = $this->container->get('bdd_service')->getPdo();


		// Formation de la requete SQL selon les paramètres donnés
		$sql = 'SELECT ligne_produit.nom as "lp_nom",produit.id as "p_id", produit.nom as "p_nom" FROM produit JOIN ligne_produit ON produit.ref_ligne_produit=ligne_produit.id ';

		if (!empty($nom) && !empty($ligneproduit))
			$sql.='WHERE produit.nom='.$pdo->quote($nom).' AND ligne_produit.nom='.$pdo->quote($ligneproduit).'';
		elseif (empty($nom) && !empty($ligneproduit))
			$sql.='WHERE ligne_produit.nom='.$pdo->quote($ligneproduit).'';
		elseif (!empty($nom) && empty($ligneproduit))
			$sql.='WHERE produit.nom='.$pdo->quote($nom).'';
		if($offset != 0) {
			$sql.='ORDER BY ligne_produit.nom ASC LIMIT '.(int)$offset;
			if ($count != 0)
				$sql.=','.(int)$count;
		}
		else{
			$sql .= 'ORDER BY ligne_produit.nom ASC';
		}

		//exécution de la requête
		$resultat = array();

		//on créé le tableau de retour à partir de la requête
		foreach ($pdo->query($sql) as $ligne) {
			$row = array('lp'=>$ligne['lp_nom'],'p_id'=>$ligne['p_id'], 'p' => $ligne['p_nom']);
			array_push($resultat,$row);
		}

		//encodage json du tableau de résultat avec ligneproduit et produit
		return json_encode($resultat);

	}
	
	/**
	 * Permet de modifier un produit
	 *
	 * @Soap\Method("modifProduit")
	 * @Soap\Param("nom_lp",phpType="string")
	 * @Soap\Param("nom_p",phpType="string")
	 * @Soap\Param("id_p",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifProduitAction($nom_lp,$nom_p, $id_p){
	
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[LP001] Vous n\'avez pas les droits nécessaires.');
	
		if(!is_string($nom_lp) || !is_int($id_p) || !is_string($nom_p)) // Vérif des arguments
			return new \SoapFault('Server','[LP002] Paramètres invalides.');
	
		//on récupere l'objet pdo connecté à la base du logiciel
		$pdo = $this->container->get('bdd_service')->getPdo();
	
		$sql_recup_lp_id = 'SELECT ligne_produit.id "lp_id" FROM ligne_produit WHERE ligne_produit.nom='.$pdo->quote($nom_lp).'';
	
		foreach ($pdo->query($sql_recup_lp_id) as $ligne_lp) {
			$id_lp = $ligne_lp['lp_id'];
		}
		// Formation de la requete SQL selon les paramètres donnés
		$sql = 'UPDATE produit SET nom='.$pdo->quote($nom_p).', ref_ligne_produit='.$pdo->quote($id_lp).' WHERE id='.$pdo->quote($id_p).'';
	
		$pdo->query($sql);
		return "OK";
	
	}
	
	/**
	 * Permet de retourner le menu et sous menu en fonction du role
	 * au formart json.
	 *
	 * @Soap\Method("getMenu")
	 * @Soap\Result(phpType = "string")
	 */
	public function getMenuAction(){
		// Verifie le role de l'utilisateur connecte
		// Si il est gerant
		if ($this->container->get('user_service')->isOk('ROLE_GERANT')) {
			$tableau_menu = array(
				array('menu' => 'caisse','sous_menu' => array()),
				array('menu' => 'client','sous_menu' => array('Info client','Stats')),
				array('menu' => 'evenement','sous_menu' => array()),
				array('menu' => 'fournisseur','sous_menu' => array('Fournisseur','Historique')),
				array('menu' => 'produit','sous_menu' => array('Article', 'Attribut','Ligne produit','Produit','Reception','Stock','Inventaire')),
				array('menu' => 'vente','sous_menu' => array('Moyen de payement','Stats','Factures','Retour')));
			return json_encode($tableau_menu);
		}
		
		// Si il est employe
 		else if ($this->container->get('user_service')->isOk('ROLE_EMPLOYE')) { 
			//TODO
 		}
 		else { // Si l'utilisateur n'est pas connecté
 			return new \SoapFault('Server','[GM001] Vous n\'avez pas les droits nécessaires.');
 		}
	}
	
	/**
	 * Permet de retourner tous les produits en fonction de la ligne de produit
	 *
	 * @Soap\Method("getProduitFromLigneProduit")
	 * @Soap\Param("LigneProduit",phpType="string")
	 * @Soap\Result(phpType = "string")
	 **/
	public function getProduitFromLigneProduitAction($LigneProduit){
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[LP001] Vous n\'avez pas les droits nécessaires.');
		
		if(!is_string($LigneProduit)) // Vérif des arguments
			return new SoapFault('Server','[LP002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
	
		// Si la ligne de produit est differente de vide
		if(!empty($LigneProduit)){
			// Recuperer tous les produits de la ligne produit
			$sql_recupere_produit = 'SELECT nom FROM alba.produit WHERE est_visible = 1
					 AND ref_ligne_produit = (SELECT id FROM alba.ligne_produit WHERE nom='.$pdo->quote($LigneProduit).')';
		}
		else{
			//Sinon on recupere tous les produits de toutes les lignes de produits.
			$sql_recupere_produit = 'SELECT nom FROM alba.produit WHERE est_visible = 1';
		}
		
		foreach ($pdo->query($sql_recupere_produit) as $row_produit) {
			$ligne = array('produit' => $row_produit['nom']);
			array_push($result, $ligne);
		}
		return json_encode($result);
	}
	
	/**
	 * Permet de retourner le stock actuel, en fonction des parametres demander.
	 *
	 * @Soap\Method("getStock")
	 * @Soap\Param("LigneProduit",phpType="string")
	 * @Soap\Param("Produit",phpType="string")
	 * @Soap\Param("Article",phpType="string")
	 * @Soap\Result(phpType = "string")
	 **/
	public function getStockAction($LigneProduit,$Produit,$Article){
		
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[LP001] Vous n\'avez pas les droits nécessaires.');
		
		if(!is_string($LigneProduit) || !is_string($Produit) || !is_string($Article)) // Vérif des arguments
			return new SoapFault('Server','[LP002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
		
		$requete_stock = 'SELECT l.nom as ligne_produit_nom,p.nom as produit_nom,a.code_barre article_code_barre,a.id as id_article FROM alba.ligne_produit l
						INNER JOIN alba.produit p ON p.ref_ligne_produit = l.id
						INNER JOIN alba.article a ON a.ref_produit = p.id';
		

		// Si l'article est renseigner. Pas de else if car l'utilisateur peut tres bien
		// selection une ligne produit puis finalement s�lectionner biper un artcile.
		// et on donnne la priorit� a l'article!
		
		if (!empty($Article)){
			$requete_stock = $requete_stock.' WHERE a.code_barre = '.$pdo->quote($Article).'';
		}
		//Si le parametre ligne de produit n'est pas vide
		else if(!empty($LigneProduit)){
			// On verifie si l'utilisateur a selectionner un produit
			// Si oui on fait la recherche par rapport a ce produit et non a la ligne produit
			if(!empty($Produit)){
				$requete_stock = $requete_stock.' WHERE p.nom = '.$pdo->quote($Produit).'';
			}
			// sinon on recherche par la ligne produit
			else{
				$requete_stock = $requete_stock.' WHERE l.nom = '.$pdo->quote($LigneProduit).'';
			}
		}
		else {
			$requete_stock = $requete_stock;
		}
		// Si l'article est renseigner. Pas de else if car l'utilisateur peut tres bien
		// selection une ligne produit puis finalement s�lectionner biper un artcile.
		// et on donnne la priorit� a l'article!
		if (!empty($Article)){
			$requete_stock = $requete_stock.' WHERE a.code_barre = '.$pdo->quote($Article).'';
		}
		$requete_stock = $requete_stock.' ORDER BY ligne_produit_nom,produit_nom ASC';
		
		foreach ($pdo->query($requete_stock) as $row_ligne) {
			$sql_quantite_article = 'SELECT SUM(quantite_mouvement) as total_mouvement FROM alba.mouvement_stock
															WHERE ref_article = '.$row_ligne['id_article'].' AND date_mouvement >= (SELECT date_mouvement FROM alba.mouvement_stock
															WHERE ref_article = '.$row_ligne['id_article'].'
															AND est_inventaire = 1
															order by date_mouvement desc limit 1)';
			// On parcourt les mouvements de stock de l'article
			foreach ($pdo->query($sql_quantite_article) as $row_quantite){
				$ligne = array('ligne_produit' => $row_ligne['ligne_produit_nom'],'produit' => $row_ligne['produit_nom'],'article'=>$row_ligne['article_code_barre'],'quantite'=>$row_quantite['total_mouvement']);
				array_push($result, $ligne);
			}
		}
		
		return json_encode($result);
		
	}
	
	/**
	 * Permet de retourner toutes les lignes produits
	 *
	 * @Soap\Method("getAllLigneProduit")
	 * @Soap\Result(phpType = "string")
	 **/
	public function getAllLigneProduitAction(){
	
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[LP001] Vous n\'avez pas les droits nécessaires.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
	
		$requete_tous_les_produits = 'SELECT nom as ligne_produit_nom FROM alba.ligne_produit ORDER BY nom ASC';
	
		foreach ($pdo->query($requete_tous_les_produits) as $row) {
			$ligne = array('nom_ligne_produit' => $row['ligne_produit_nom']);
			array_push($result, $ligne);
		}
		return json_encode($result);
	}
	
	/**
	* @Soap\Method("getFournisseurs")
	* @Soap\Param("count",phpType="int")
	* @Soap\Param("offset",phpType="int")
	* @Soap\Param("nom",phpType="string")
	* @Soap\Param("email",phpType="string")
	* @Soap\Param("telephone_portable",phpType="string")
	* @Soap\Result(phpType = "string")
	*/
	public function getFournisseursAction($count, $offset, $nom,$email,$telephone_portable) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GF001] Vous n\'avez pas les droits nécessaires.');

		if(!is_string($nom) || !is_int($offset) || !is_int($count)) // Vérif des arguments
			return new \SoapFault('Server','[GF002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		// Formation de la requete SQL
		//$sql = 'SELECT id, nom, email, telephone_portable FROM fournisseur WHERE email='.$pdo->quote($email).'';

		$sql = 'SELECT id, nom, email, telephone_portable FROM fournisseur ';
		if (!empty($nom) && !empty($email) && !empty($telephone_portable))
			$sql.='WHERE nom='.$pdo->quote($nom).' AND email='.$pdo->quote($email).'
			AND telephone_portable='.$pdo->quote($telephone_portable).'';

		if (empty($nom) && !empty($email) && !empty($telephone_portable))
			$sql.='WHERE email='.$pdo->quote($email).'
			AND telephone_portable='.$pdo->quote($telephone_portable).'';

		if (empty($nom) && empty($email) && !empty($telephone_portable))
			$sql.='WHERE telephone_portable='.$pdo->quote($telephone_portable).'';


		if (!empty($nom) && empty($email) && empty($telephone_portable))
			$sql.='WHERE nom='.$pdo->quote($nom).'';

		if (!empty($nom) && !empty($email) && empty($telephone_portable))
			$sql.='WHERE nom='.$pdo->quote($nom).' AND email='.$pdo->quote($email).'';

		if (!empty($nom) && empty($email) && !empty($telephone_portable))
			$sql.='WHERE nom='.$pdo->quote($nom).' AND telephone_portable='.$pdo->quote($telephone_portable).'';

		if (empty($nom) && !empty($email) && empty($telephone_portable))
			$sql.='WHERE email='.$pdo->quote($email).'';
		if($offset != 0) {
			$sql.=' ORDER BY nom ASC LIMIT '.(int)$offset;
			if ($count != 0)
				$sql.=','.(int)$count;
		}
		else{
			$sql .=' ORDER BY nom ASC';
		}

		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('id' => $row['id'], 'nom' => $row['nom'],'email'=>$row['email'],'telephone_portable'=>$row['telephone_portable']);
			array_push($result, $ligne);
		}

		return json_encode($result);
	}

	/**
	 * @Soap\Method("ajoutFournisseur")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("email",phpType="string")
	 * @Soap\Param("telephone_portable",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutFournisseurAction($nom,$email,$telephone_portable)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[AF001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom)) // Vérif des arguments
			return new \SoapFault('Server', '[AF002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		//$result = array();

		// Formation de la requete SQL
		$sql = 'SELECT id, nom, email, telephone_portable FROM fournisseur WHERE nom='.$pdo->quote($nom).'';

		$resultat = $pdo->query($sql);
		if($resultat->rowCount($sql) == 0) {

			//on insert le fournisseur
			$sql = 'INSERT INTO fournisseur(nom,email,telephone_portable)VALUES(' . $pdo->quote($nom) . ','.$pdo->quote($email).',
			'.$pdo->quote($telephone_portable).');';
			$pdo->query($sql);

			return "OK";
		}

		return new \SoapFault('Server','[AF003] Paramètres invalides.');

	}

	/**
	 * @Soap\Method("modifFournisseur")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("email",phpType="string")
	 * @Soap\Param("telephone_portable",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifFournisseurAction($id,$nom,$email,$telephone_portable)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MF001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom) || !is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[MF002] Paramètres invalides.');


		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		// Formation de la requete SQL
		$sql = 'UPDATE fournisseur SET nom='.$pdo->quote($nom).',email='.$pdo->quote($email).',telephone_portable='.$pdo->quote($telephone_portable).'
		WHERE id='.$pdo->quote($id).'';

		$resultat = $pdo->query($sql);
		$pdo->query($sql);

		return "OK";

	}



	/**
	 * @Soap\Method("getAdresses")
	 * @Soap\Param("count",phpType="int")
	 * @Soap\Param("offset",phpType="int")
	 * @Soap\Param("pays",phpType="string")
	 * @Soap\Param("ville",phpType="string")
	 * @Soap\Param("voie",phpType="string")
	 * @Soap\Param("num_voie",phpType="string")
	 * @Soap\Param("code_postal",phpType="string")
	 * @Soap\Param("num_appartement",phpType="string")
	 * @Soap\Param("telephone_fixe",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getAdressesAction($count, $offset, $pays, $ville, $voie, $num_voie, $code_postal, $num_appartement,$telephone_fixe)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GA001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($pays) || !is_string($ville) || !is_string($voie) || !is_string($num_voie) || !is_string($code_postal)
			|| !is_string($num_appartement) || !is_string($telephone_fixe)
			|| !is_int($offset) || !is_int($count)) // Vérif des arguments
			return new \SoapFault('Server', '[GA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		// Formation de la requete SQL
		$sql = 'SELECT id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe FROM adresse ';

		$arguments = array();
		if(!empty($pays) || !empty($ville) || !empty($voie) || !empty($num_voie) || !empty($code_postal) || !empty($num_appartement)
			|| !empty($telephone_fixe)){

			if(!empty($pays))
				array_push($arguments,array('pays'=>$pays));
			if(!empty($ville))
				array_push($arguments,array('ville'=>$ville));
			if(!empty($voie))
				array_push($arguments,array('voie'=>$voie));
			if(!empty($num_voie))
				array_push($arguments,array('num_voie'=>$num_voie));
			if(!empty($num_appartement))
				array_push($arguments,array('num_appartement'=>$num_appartement));
			if(!empty($code_postal))
				array_push($arguments,array('code_postal'=>$code_postal));
			if(!empty($telephone_fixe))
				array_push($arguments,array('telephone_fixe'=>$telephone_fixe));

			$sql.='WHERE ';
			//on recupere la derniere clef du tableau
			$lastKey = key(end($arguments));
			reset($arguments);
			while($arg = current($arguments)){
				$val = '%'.$arg.'%';
				if(key($arg)==$lastKey){
					$sql .= key($arg).' LIKE '.$pdo->quote($val).' ';
				}
				else{
					$sql .= key($arg).' LIKE '.$pdo->quote($val).' AND ';
				}
				next($arguments);
			}
			$sql .= '';
			if ($offset != 0) {
				$sql .= ' ORDER BY ville ASC LIMIT ' . (int)$offset;
				if ($count != 0)
					$sql .= ',' . (int)$count;
			} else {
				$sql .= ' ORDER BY ville ASC';
			}
		}

		//id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('id' => $row['id'], 'pays' => $row['pays'], 'ville' => $row['ville'], 'voie' => $row['voie'],
				'num_voie'=>$row['num_voie'],'code_postal'=>$row['code_postal'],'num_appartement'=>$row['num_appartement'],
				'telephone_fixe'=>$row['telephone_fixe']);
			array_push($result, $ligne);
		}

		return json_encode($result);
	}

	/**
	 * @Soap\Method("ajoutAdresse")
	 * @Soap\Param("est_fournisseur",phpType="boolean")
	 * @Soap\Param("ref_id",phpType="string")
	 * @Soap\Param("pays",phpType="string")
	 * @Soap\Param("ville",phpType="string")
	 * @Soap\Param("voie",phpType="string")
	 * @Soap\Param("num_voie",phpType="string")
	 * @Soap\Param("code_postal",phpType="string")
	 * @Soap\Param("num_appartement",phpType="string")
	 * @Soap\Param("telephone_fixe",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutAdresseAction($est_fournisseur,$ref_id,$pays,$ville, $voie, $num_voie, $code_postal, $num_appartement,$telephone_fixe)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[AA001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($pays) || !is_string($ville) || !is_string($voie) || !is_string($num_voie) || !is_string($code_postal)
			|| !is_string($num_appartement) || !is_string($telephone_fixe)
		|| !is_bool($est_fournisseur) || !is_string($ref_id)) // Vérif des arguments
			return new \SoapFault('Server', '[AA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		// Formation de la requete SQL
		$tab_pays = json_decode($pays);
		$tab_ville = json_decode($ville);
		$tab_voie = json_decode($ville);
		$tab_num_voie = json_decode($ville);
		$tab_code_postal = json_decode($code_postal);
		$tab_num_appartement = json_decode($num_appartement);
		$tab_telephone_fixe = json_decode($telephone_fixe);

		$i=0;
		foreach($tab_pays as $pays){

			$ville = $tab_ville[$i];
			$voie = $tab_voie[$i];
			$num_voie = $tab_num_voie[$i];
			$code_postal = $tab_code_postal[$i];
			$num_appartement = $tab_num_appartement[$i];
			$telephone_fixe = $tab_telephone_fixe[$i];


			$sql = 'SELECT id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe FROM adresse
WHERE pays='.$pdo->quote($pays).' AND ville='.$pdo->quote($ville).' AND voie='.$pdo->quote($voie).'
AND num_voie='.$pdo->quote($num_voie).' ';

			if($est_fournisseur)
				$sql .= 'AND ref_fournisseur='.$pdo->quote($ref_id).'';
			else
				$sql .= 'AND ref_contact='.$pdo->quote($ref_id).'';

			//on teste si l'adresse existe déjà
			$resultat = $pdo->query($sql);

			if($resultat->rowCount() == 0){
				//insertion des données
				if($est_fournisseur){
					$sql='INSERT INTO adresse(ref_fournisseur,pays,ville,voie,num_voie,code_postal,num_appartement,telephone_fixe) VALUES(
'.$pdo->quote($ref_id).','.$pdo->quote($pays).','.$pdo->quote($ville).','.$pdo->quote($voie).','.$pdo->quote($num_voie).',
'.$pdo->quote($code_postal).','.$pdo->quote($num_appartement).','.$pdo->quote($telephone_fixe).')';
				}
				else{
					$sql='INSERT INTO adresse(ref_contact,pays,ville,voie,num_voie,code_postal,num_appartement,telephone_fixe) VALUES(
'.$pdo->quote($ref_id).','.$pdo->quote($pays).','.$pdo->quote($ville).','.$pdo->quote($voie).','.$pdo->quote($num_voie).',
'.$pdo->quote($code_postal).','.$pdo->quote($num_appartement).','.$pdo->quote($telephone_fixe).')';
				}
				$pdo->query($sql);

				//return new \SoapFault('Server','[AA00011] '.$sql.'.');


			}
			else{
				return new \SoapFault('Server','[AA002] Paramètres invalides.');
			}

			$i++;
		}
		return "OK";


	}

	/**
	 * @Soap\Method("modifAdresse")
	 * @Soap\Param("id_ad",phpType="int")
	 * @Soap\Param("pays",phpType="string")
	 * @Soap\Param("ville",phpType="string")
	 * @Soap\Param("voie",phpType="string")
	 * @Soap\Param("num_voie",phpType="string")
	 * @Soap\Param("code_postal",phpType="string")
	 * @Soap\Param("num_appartement",phpType="string")
	 * @Soap\Param("telephone_fixe",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifAdresseAction($id_ad,$pays,$ville, $voie, $num_voie, $code_postal, $num_appartement,$telephone_fixe){

		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MA001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($pays) || !is_string($ville) || !is_string($voie) || !is_string($num_voie) || !is_string($code_postal)
			|| !is_string($num_appartement) || !is_string($telephone_fixe)|| !is_int($id_ad)) // Vérif des arguments
			return new \SoapFault('Server', '[MA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		//Formation de la requête SQL
		$sql = 'UPDATE adresse SET pays='.$pdo->quote($pays).', ville='.$pdo->quote($ville).', voie='.$pdo->quote($voie).',
		num_voie='.$pdo->quote($num_voie).',code_postal='.$pdo->quote($code_postal).',num_appartement='.$num_appartement.',
		telephone_fixe='.$pdo->quote($telephone_fixe).' WHERE id='.$pdo->quote($id_ad).'';

		$pdo->query($sql);
		return "OK";

	}

}
