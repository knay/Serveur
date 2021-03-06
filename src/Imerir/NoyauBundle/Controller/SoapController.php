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
	public function loginAction($username, $passwd)
	{
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
		if (count($users) === 0) {
			return new \SoapFault('Server', 'Le mot de passe que vous avez saisi est incorrect.');
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
		$queryUser = $dm->createQuery($sql)->setParameters(array('username' => $username, 'passwd' => $hash));

		//on récupère toutes les lignes de la requête
		$users = $queryUser->getResult();
		//on teste si il y a bien un utilisateur username avec le mot de passe passwd
		if (count($users) !== 0) {
			//on lit la première lignes
			$u = $users[0];

			$token = new UsernamePasswordToken($u->getUsername(), $u->getPassword(), 'main', $u->getRoles());
			$context = $this->container->get('security.context');
			$context->setToken($token);

			$retourJson = array('token' => $this->container->get('request')->cookies->get('PHPSESSID'),
				'username' => $username,
				'role' => $u->getRoles()[0]);
			return json_encode($retourJson);
		} else {
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
	public function enregistrerAchatAction($articles)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[EA001] Vous n\'avez pas les droits nécessaires.');
		if (!is_string($articles)) // Vérif des arguments
			return new \SoapFault('Server', '[EA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo();
		$tabArticles = json_decode($articles);
		
		if (isset($tabArticles->idClient) && $tabArticles->idClient > 0) { // Si un client est associé à la facture
			if (!isset ($tabArticles->moyenPaiement) || $tabArticles->moyenPaiement < 1) // Si le moyen de paiement n'est pas renseigné
				$sql = 'INSERT INTO facture (date_facture, est_visible, ref_contact) VALUE (NOW(), true, '.(int)$tabArticles->idClient.')';
			else 
				$sql = 'INSERT INTO facture (date_facture, est_visible, ref_contact, ref_moyen_paiement) VALUE (NOW(), true, '.(int)$tabArticles->idClient.', '.(int)$tabArticles->moyenPaiement.')';
		}
		else { // Si aucun client n'est associé
			if (!isset ($tabArticles->moyenPaiement) || $tabArticles->moyenPaiement < 1) // Si aucun moyen de paiement n'est associé
				$sql = 'INSERT INTO facture (date_facture, est_visible) VALUE (NOW(), true)';
			else
				$sql = 'INSERT INTO facture (date_facture, est_visible, ref_moyen_paiement) VALUE (NOW(), true, '.(int)$tabArticles->moyenPaiement.')';
		}
		
		$resultat = $pdo->query($sql);
		$ref_facture = $pdo->lastInsertId();

		foreach ($tabArticles->article as $article) {
			$code_barre = $article->codeBarre;
			$quantite = $article->quantite;
			$promo = $article->promo;
			$prix = 0;

			if ($code_barre === '')
				break;

			$sql = 'SELECT montant_client FROM prix JOIN article ON ref_article=article.id WHERE code_barre=' . $pdo->quote($code_barre);
			$resultat = $pdo->query($sql);
			foreach ($resultat as $row) {
				$prix = floatval($row['montant_client']);
			}

			$sql = 'INSERT INTO mouvement_stock (ref_article, date_mouvement, quantite_mouvement, est_inventaire, est_visible)
					VALUE ((SELECT id FROM article WHERE code_barre=' . $pdo->quote($code_barre) . '),
							NOW(), \'' . (int)-$quantite . '\', false, true)';

			$resultat = $pdo->query($sql);
			$ref_mvt_stock = $pdo->lastInsertId();

			$ref_remise = 0;
			if (0 !== $promo) { // S'il y a une promo on l'enregistre dans la table remise
				$sql = 'INSERT INTO remise (reduction, type_reduction) VALUE (' . (int)$promo . ', \'taux\')';
				$resultat = $pdo->query($sql);
				$ref_remise = $pdo->lastInsertId();
			}

			if ($ref_remise !== 0)
				$sql = 'INSERT INTO ligne_facture (ref_facture, ref_mvt_stock, ref_remise)
				     	VALUE (' . (int)$ref_facture . ', ' . (int)$ref_mvt_stock . ', ' . (int)$ref_remise . ')';
			else
				$sql = 'INSERT INTO ligne_facture (ref_facture, ref_mvt_stock)
				     	VALUE (' . (int)$ref_facture . ', ' . (int)$ref_mvt_stock . ')';
			$resultat = $pdo->query($sql);
		}

		return '';
	}

	/**
	 * @Soap\Method("rechercheArticle")
	 * @Soap\Param("nomLigneProduit",phpType="string")
	 * @Soap\Param("nomProduit",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function rechercheArticleAction($nomLigneProduit, $nomProduit) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[RA001] Vous n\'avez pas les droits nécessaires.');
	
		if (!is_string($nomLigneProduit) || !is_string($nomProduit)) // Vérif des arguments
			return new \SoapFault('Server', '[RA002] Paramètres invalides.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
		$nomLigneProduit = '%'.$nomLigneProduit.'%';
		$nomProduit = '%'.$nomProduit.'%';
		
		$sql = 'SELECT code_barre, produit.nom AS nomProduit, ligne_produit.nom AS nomLigne, 
				       attribut.nom AS attribut, valeur_attribut.libelle AS valAttribut
				FROM article 
				JOIN produit ON article.ref_produit = produit.id
				JOIN ligne_produit ON produit.ref_ligne_produit = ligne_produit.id
				LEFT OUTER JOIN article_a_pour_val_attribut ON article_a_pour_val_attribut.ref_article = article.id
				LEFT OUTER JOIN valeur_attribut ON article_a_pour_val_attribut.ref_val_attribut = valeur_attribut.id
				LEFT OUTER JOIN attribut ON valeur_attribut.ref_attribut = attribut.id
				WHERE produit.nom LIKE '.$pdo->quote($nomProduit).' AND ligne_produit.nom LIKE '.$pdo->quote($nomLigneProduit).'
				      AND produit.est_visible = true AND ligne_produit.est_visible = true
				      AND article.est_visible = true AND (attribut.est_visible = true OR article_a_pour_val_attribut.ref_article IS null)
				ORDER BY article.code_barre ASC';
		
		$resultat = $pdo->query($sql);
		
		$ligne = array();
		$tabAttributs = array();
		$dernierCodeBarre = '';
		$dernierLigneProduit = '';
		$dernierProduit = '';
		$dernierAttribut = '';
		foreach ($resultat as $row) {
			if ($dernierCodeBarre !== $row['code_barre']) {
				$ligne = array('codeBarre' => $dernierCodeBarre, 'ligneProduit' => $dernierLigneProduit, 
						       'produit' => $dernierProduit, 'valAttribut' => $tabAttributs);
				array_push($result, $ligne);
				$tabAttributs = array();
			}
			$dernierLigneProduit = $row['nomLigne'];
			$dernierProduit = $row['nomProduit'];
			$dernierCodeBarre = $row['code_barre'];
			$dernierAttribut = $row['attribut'];
			array_push($tabAttributs, $row['valAttribut']);
		}
		
		$ligne = array('codeBarre' => $dernierCodeBarre, 'ligneProduit' => $dernierLigneProduit, 
					   'produit' => $dernierProduit, 'valAttribut' => $tabAttributs);
		array_push($result, $ligne);
		
		return json_encode($result);
	}
	
	/**
	 * @Soap\Method("supprimerArticle")
	 * @Soap\Param("codeBarre",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function supprimerArticleAction($codeBarre) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SUA001] Vous n\'avez pas les droits nécessaires.');
		
		if (!is_string($codeBarre)) // Vérif des arguments
			return new \SoapFault('Server', '[SUA002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		
		if ($codeBarre !== '') {
			$sql = 'UPDATE article SET est_visible=false WHERE code_barre='.$pdo->quote($codeBarre);
			$pdo->query($sql);
		}
		else {
			return new \SoapFault('Server', '[SUA003] Paramètres invalides.');
		}
		
		return '';
	}
	
	/**
	 * @Soap\Method("modifArticle")
	 * @Soap\Param("article",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifArticleAction($article)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MA001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($article)) // Vérif des arguments
			return new \SoapFault('Server', '[MA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$tabArticle = json_decode($article);
		$result = array();

		$code_barre = $tabArticle->codeBarre;
		$nom_produit = $tabArticle->produit;
		$attributs = $tabArticle->attributs;
		$prixClient = $tabArticle->prixClient;
		$prixFournisseur = $tabArticle->prixFournisseur;
		$quantite = -1;
		if(isset ($tabArticle->quantite) && is_int($tabArticle->quantite) && $tabArticle->quantite >= 0) {
			$quantite = $tabArticle->quantite;
		}

		$sql = 'SELECT * FROM article WHERE code_barre=' . $pdo->quote($code_barre);
		$resultat = $pdo->query($sql);

		if ($resultat->rowCount() == 0) { // Si l'article n'existe pas on l'ajoute
			$sql = 'SELECT id FROM produit WHERE nom=' . $pdo->quote($nom_produit); // On récup l'id du produit
			$resultat = $pdo->query($sql);

			foreach ($pdo->query($sql) as $row) {
				$idProduit = $row['id'];
			}

			$sql = 'INSERT INTO article(ref_produit, code_barre, est_visible) 
				    VALUE (\'' . $idProduit . '\', ' . $pdo->quote($code_barre) . ', TRUE)';
			$resultat = $pdo->query($sql);
			$idArticle = $pdo->lastInsertId(); // On récup l'id de l'article créé
		} else { // Si l'article existe on recup juste son ID
			foreach ($resultat as $row) {
				$idArticle = $row['id'];
			}
			$sql = 'UPDATE article SET ref_produit=
					(SELECT id FROM produit WHERE nom=' . $pdo->quote($nom_produit) . ')
				    WHERE article.id = \'' . $idArticle . '\'';
			$resultat = $pdo->query($sql);
		}

		$sql = 'INSERT INTO prix(ref_article, montant_fournisseur, montant_client, date_modif)
					VALUE (\'' . $idArticle . '\', ' . (float)$prixFournisseur . ', \'' . (float)$prixClient . '\', NOW())';
		$resultat = $pdo->query($sql);

		$sql = 'DELETE FROM article_a_pour_val_attribut WHERE ref_article=\'' . $idArticle . '\'';
		$resultat = $pdo->query($sql); // On vide la table de correspondance pour cet article
		
		if($quantite >= 0) { // Si on a précisé une quantite on vient l'insérer
			$sql = 'INSERT INTO mouvement_stock (ref_article, quantite_mouvement, date_mouvement, est_inventaire)
					VALUES (\'' . $idArticle . '\', ' . $quantite . ', NOW(), TRUE)'; // Insertion du mouvement de stock
			$resultat = $pdo->query($sql); // On valide l'inventaire de ce produit
		}

		// On parcourt toutes les valeur d'attributs de cette article pour les enregistrer
		foreach ($attributs as $nomAttribut => $libelleValeurAttribut) {
			$sql = 'SELECT valeur_attribut.id AS vaid, attribut.id AS aid FROM valeur_attribut
			        JOIN attribut ON ref_attribut = attribut.id
			        WHERE attribut.nom=' . $pdo->quote($nomAttribut) . ' AND valeur_attribut.libelle=' . $pdo->quote($libelleValeurAttribut);

			foreach ($pdo->query($sql) as $row) {
				$idValAttribut = $row['vaid'];
			}

			$sql = 'INSERT INTO article_a_pour_val_attribut (ref_article, ref_val_attribut)
					VALUE (\'' . $idArticle . '\', \'' . $idValAttribut . '\')'; // Insertion de la valeur attribut
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
	public function ajoutLigneProduitAction($nom)
	{
		//on teste si l'utilisateur a les droits pour accéder à cette fonction
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[ALP001] Vous n\'avez pas les droits nécessaires.');
		if (!is_string($nom) || $nom=='') // Vérif des arguments
			return new \SoapFault('Server', '[ALP002] Paramètres invalides.');
		try {
			$pdo = $this->container->get('bdd_service')->getPdo();
			//on verifie si il y a deja la ligne produit
			$sql = 'SELECT * FROM ligne_produit WHERE nom=' . $pdo->quote($nom) . ' AND est_visible=true ';

			$resultat = $pdo->query($sql);
			//si la ligne produit n'existe pas
			if ($resultat->rowCount() == 0) {
				$sql = 'INSERT INTO ligne_produit(nom)VALUES(' . $pdo->quote($nom) . ')';
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
	 * Permet d'enregistrer un retour client.
	 *
	 * @Soap\Method("enregistrerRetour")
	 * @Soap\Param("idFacture",phpType="int")
	 * @Soap\Param("quantite",phpType="int")
	 * @Soap\Param("code_barre",phpType="string")
	 * @Soap\Param("promo",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function enregistrerRetourAction($idFacture, $quantite, $code_barre, $promo)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[ER001] Vous n\'avez pas les droits nécessaires.');
		if (!is_string($code_barre) || !is_int($idFacture) || !is_int($quantite) || !is_int($promo)) // Vérif des arguments
			return new \SoapFault('Server', '[ER002] Paramètres invalides lors de l\'enregistrement du retour.');
	
		$pdo = $this->container->get('bdd_service')->getPdo();
		
		if ($quantite < 0)
			$quantite = -1*$quantite;
	
		if ($code_barre === '')
			return new \SoapFault('Server', '[ER003] Paramètres invalides lors de l\'enregistrement du retour.');

		$sql = 'INSERT INTO mouvement_stock (ref_article, date_mouvement, quantite_mouvement, est_inventaire, est_visible)
				VALUE ((SELECT id FROM article WHERE code_barre=' . $pdo->quote($code_barre) . '),
						NOW(), \'' . (int)$quantite . '\', false, true)';

		$resultat = $pdo->query($sql);
		$ref_mvt_stock = $pdo->lastInsertId();

		$ref_remise = 0;
		if (0 !== $promo) { // S'il y a une promo on l'enregistre dans la table remise
			$sql = 'INSERT INTO remise (reduction, type_reduction) VALUE (' . (int)$promo . ', \'taux\')';
			$resultat = $pdo->query($sql);
			$ref_remise = $pdo->lastInsertId();
		}

		if ($ref_remise !== 0)
			$sql = 'INSERT INTO ligne_facture (ref_facture, ref_mvt_stock, ref_remise)
			     	VALUE (' . (int)$idFacture . ', ' . (int)$ref_mvt_stock . ', ' . (int)$ref_remise . ')';
		else
			$sql = 'INSERT INTO ligne_facture (ref_facture, ref_mvt_stock)
			     	VALUE (' . (int)$idFacture . ', ' . (int)$ref_mvt_stock . ')';
		$resultat = $pdo->query($sql);
	
		return '';
	}

	/**
	 * Permet de récupérer tous les code barres de tout les articles.
	 *
	 * @Soap\Method("getAllCodeBarre")
	 * @Soap\Result(phpType = "string")
	 */
	public function getAllCodeBarreAction() {
		if (!($this->container->get('user_service')->isOk('ROLE_EMPLOYE')) &&
			!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GACB001] Vous n\'avez pas les droits nécessaires.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
	
		$sql = 'SELECT code_barre FROM article';
	
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			array_push($result, $row['code_barre']);
		}
	
		return json_encode($result);
	}
	
	/**
	 * @Soap\Method("supprLigneProduit")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function supprLigneProduit($id){
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SLP001] Vous n\'avez pas les droits nécessaires.');

		if (!is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[SP002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service 
		
		$sql = 'UPDATE ligne_produit SET est_visible=0 WHERE id='.$pdo->quote($id).'';
		
		$pdo->query($sql);
		
		return "OK";
		
	}
	
	/**
	 * Permet de récupérer une ligne de produit.
	 * @param $count Le nombre d'enregistrement voulu, 0 pour tout avoir
	 * @param $offset Le décalage par rapport au début des enregistrements
	 * @param $nom Si vous voulez une ligne de produit spécifique (interet ?).
	 *
	 * @Soap\Method("getLigneProduit")
	 * @Soap\Param("count",phpType="int")
	 * @Soap\Param("offset",phpType="int")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("attribut_nom",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getLigneProduitAction($count, $offset, $nom, $attribut_nom)
	{


		//return new \SoapFault('Server',$nom);
		
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GLP001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom) || !is_int($offset) || !is_int($count)) // Vérif des arguments
			return new \SoapFault('Server', '[GLP002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service 
		$result = array();

		// Formation de la requete SQL
		$sql = 'SELECT ligne_produit.id, ligne_produit.nom, GROUP_CONCAT(attribut.nom) as "attribut_nom" FROM ligne_produit
left outer join ligne_produit_a_pour_attribut on ligne_produit_a_pour_attribut.ref_ligne_produit = ligne_produit.id
left outer join attribut on ligne_produit_a_pour_attribut.ref_attribut = attribut.id ';
		//return new \SoapFault('Server',$sql);
		if (!empty($nom) && !empty($attribut_nom)){
			$attribut_nom = '%'.$attribut_nom.'%';
			$nom = '%'.$nom.'%';
			$sql .= 'WHERE ligne_produit.nom LIKE ' . $pdo->quote($nom) . ' AND attribut.nom LIKE '.$pdo->quote($attribut_nom).'
					 AND ligne_produit.est_visible=\'1\'';
		}

		if (!empty($nom) && empty($attribut_nom)){
			$nom = '%'.$nom.'%';
			$sql .= 'WHERE ligne_produit.nom LIKE ' . $pdo->quote($nom) . ' AND ligne_produit.est_visible=\'1\'';
			//return new \SoapFault('Server',$sql);
		}

		if (empty($nom) && !empty($attribut_nom)){
			$attribut_nom = '%'.$attribut_nom.'%';
			$sql .= 'WHERE attribut.nom LIKE ' . $pdo->quote($attribut_nom) . ' AND ligne_produit.est_visible=\'1\'';
		}
		if(empty($nom) && empty($attribut_nom)){
			$sql .= 'WHERE ligne_produit.est_visible=\'1\'';
		}
		$sql.= 'group by ligne_produit.id,ligne_produit.nom';
		//return new \SoapFault('Server',$sql);
		if ($offset != 0) {
			$sql .= ' ORDER BY ligne_produit.nom ASC LIMIT ' . (int)$offset;
			if ($count != 0)
				$sql .= ',' . (int)$count;
		} else {
			$sql .= ' ORDER BY ligne_produit.nom ASC';
		}

		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('id' => $row['id'], 'nom' => $row['nom'],'attribut_nom'=>$row['attribut_nom']);
			array_push($result, $ligne);
		}

		return json_encode($result);
		//return new \SoapFault('Server',$sql);
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
	public function modifLigneProduitAction($id, $nom)
	{

		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MLP001] Vous n\'avez pas les droits nécessaires.');


		if (!is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[MLP002] Paramètre invalide.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		//$result = array();

		// Formation de la requete SQL
		$sql = 'UPDATE ligne_produit SET nom=' . $pdo->quote($nom) . ' WHERE id=' . $pdo->quote($id) . ' ';

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
	public function faireInventaireAction($articles, $avecPrix)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_EMPLOYE')) &&
			!($this->container->get('user_service')->isOk('ROLE_GERANT'))
		) // On check les droits
			return new \SoapFault('Server', '[INV001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($articles) || !is_bool($avecPrix)) // Vérif des arguments
			return new \SoapFault('Server', '[INV002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$tabArticles = json_decode($articles);

		foreach ($tabArticles as $article) {
			if ('' === $article->codeBarre)
				break;
			
			$code_barre = $article->codeBarre;
			$nom_produit = $article->produit;
			$quantite = $article->quantite;
			$attributs = $article->attributs;

			if ($avecPrix) {
				$prixClient = $article->prixClient;
				$prixFournisseur = $article->prixFournisseur;
			}

			if (empty($code_barre)) // Si pas de code barre on enregistre pas c'est pas normal
				break;

			$sql = 'SELECT id FROM article WHERE code_barre=' . $pdo->quote($code_barre); // On cherche si l'article existe ou non
			$resultat = $pdo->query($sql);

			if ($resultat->rowCount() == 0) { // Si l'article n'existe pas on l'ajoute
				$sql = 'SELECT id FROM produit WHERE nom=' . $pdo->quote($nom_produit); // On récup l'id du produit
				$resultat = $pdo->query($sql);

				foreach ($pdo->query($sql) as $row) {
					$idProduit = $row['id'];
				}

				$sql = 'INSERT INTO article(ref_produit, code_barre, est_visible) 
					    VALUE (\'' . $idProduit . '\', ' . $pdo->quote($code_barre) . ', TRUE)';
				$resultat = $pdo->query($sql);
				$idArticle = $pdo->lastInsertId(); // On récup l'id de l'article créé
			} else { // Si l'article existe on recup juste son ID
				foreach ($resultat as $row) {
					$idArticle = $row['id'];
				}
				$sql = 'UPDATE article SET ref_produit=
						(SELECT id FROM produit WHERE nom=' . $pdo->quote($nom_produit) . ')
					    WHERE article.id = \'' . $idArticle . '\'';
				$resultat = $pdo->query($sql);
			}

			if ($avecPrix) { // Si on enregistre le prix avec
				$sql = 'INSERT INTO prix(ref_article, montant_fournisseur, montant_client, date_modif)
							VALUE (\'' . $idArticle . '\', \'' . (float)$prixFournisseur . '\', \'' . (float)$prixClient . '\', NOW())';
				$resultat = $pdo->query($sql);
			}

			$sql = 'INSERT INTO mouvement_stock (ref_article, quantite_mouvement, date_mouvement, est_inventaire)
					VALUES (\'' . $idArticle . '\', ' . $quantite . ', NOW(), TRUE)'; // Insertion du mouvement de stock
			$resultat = $pdo->query($sql);

			$sql = 'DELETE FROM article_a_pour_val_attribut WHERE ref_article=\'' . $idArticle . '\'';
			$resultat = $pdo->query($sql); // On vide la table de correspondance pour cet article

			// On parcourt toutes les valeur d'attributs de cette article pour les enregistrer
			foreach ($attributs as $nomAttribut => $libelleValeurAttribut) {
				/*if ($nomAttribut !== '' && $libelleValeurAttribut !== '')
					break;*/
				
				$sql = 'SELECT valeur_attribut.id AS vaid, attribut.id AS aid FROM valeur_attribut
				        JOIN attribut ON ref_attribut = attribut.id
				        WHERE attribut.nom=' . $pdo->quote($nomAttribut) . ' AND valeur_attribut.libelle=' . $pdo->quote($libelleValeurAttribut);

				foreach ($pdo->query($sql) as $row) {
					$idValAttribut = $row['vaid'];
				}

				$sql = 'INSERT INTO article_a_pour_val_attribut (ref_article, ref_val_attribut)
						VALUE (\'' . $idArticle . '\', \'' . $idValAttribut . '\')'; // Insertion de la valeur attribut
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
	public function getArticleFromCodeBarreAction($codeBarre)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_EMPLOYE')) &&
			!($this->container->get('user_service')->isOk('ROLE_GERANT'))
		) // On check les droits
			return new \SoapFault('Server', '[GAFCB001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($codeBarre)) // Vérif des arguments
			return new \SoapFault('Server', '[GAFCB002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$reponse = array();

		$sql = 'SELECT produit.nom AS nomProduit, attribut.nom AS nomAttribut, valeur_attribut.libelle AS nomValAttribut, 
				ligne_produit.nom AS nomLigneProduit
				FROM article
				JOIN produit ON article.ref_produit = produit.id
				JOIN ligne_produit ON produit.ref_ligne_produit = ligne_produit.id
				LEFT OUTER JOIN article_a_pour_val_attribut ON article_a_pour_val_attribut.ref_article = article.id
				LEFT OUTER JOIN valeur_attribut ON article_a_pour_val_attribut.ref_val_attribut = valeur_attribut.id
				LEFT OUTER JOIN attribut ON valeur_attribut.ref_attribut = attribut.id
				WHERE article.code_barre = ' . $pdo->quote($codeBarre);
		$resultat = $pdo->query($sql);

		$reponse['attributs'] = array();
		foreach ($resultat as $row) {
			$reponse['nomProduit'] = $row['nomProduit'];
			$reponse['nomLigneProduit'] = $row['nomLigneProduit'];
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
	public function getAttributFromNomProduitAction($nom)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_EMPLOYE')) &&
			!($this->container->get('user_service')->isOk('ROLE_GERANT'))
		) // On check les droits
			return new \SoapFault('Server', '[GAFNP001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom)) // Vérif des arguments
			return new \SoapFault('Server', '[GAFNP002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
		$tabValAttribut = array();

		// Formation de la requete SQL
		$sql = 'SELECT att_nom AS nom, libelle, est_visible, aid FROM (
				SELECT attribut.id AS aid, produit.nom, attribut.nom AS "att_nom", valeur_attribut.libelle, valeur_attribut.est_visible
				FROM produit 
				JOIN ligne_produit ON ligne_produit.id = produit.ref_ligne_produit
				JOIN ligne_produit_a_pour_attribut ON ligne_produit_a_pour_attribut.ref_ligne_produit = ligne_produit.id
				JOIN attribut ON attribut.id=ligne_produit_a_pour_attribut.ref_attribut
				JOIN valeur_attribut ON attribut.id = valeur_attribut.ref_attribut
				)t
				WHERE est_visible = TRUE AND nom=' . $pdo->quote($nom) .
			'GROUP BY aid, att_nom, libelle';

		$dernierNomAttribut = '';
		$dernierId = 0;
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			if ($dernierNomAttribut === '') { // Premier tour de boucle, on a pas encore de nom d'attribut
				$dernierNomAttribut = $row['nom'];
				$dernierId = $row['aid'];
			}

			if ($row['nom'] !== $dernierNomAttribut) { // Si on change de nom d'attribut, on a fini de travailler avec ses valeurs donc on push
				array_push($result, array('nom' => $dernierNomAttribut, 'id'=> $dernierId, 'valeurs' => $tabValAttribut));
				$tabValAttribut = array();
				$dernierNomAttribut = $row['nom'];
				$dernierId = $row['aid'];
			}
			array_push($tabValAttribut, $row['libelle']);
		}
		array_push($result, array('nom' => $dernierNomAttribut, 'id'=> $dernierId, 'valeurs' => $tabValAttribut));

		return json_encode($result);
	}


	/**
	 * @Soap\Method("supprimerAttribut")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function supprimerAttributAction($id) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SUATTR001] Vous n\'avez pas les droits nécessaires.');
	
		if (!is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[SUATTR002] Paramètres invalides.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
	
		if ($id !== 0) {
			$sql = 'UPDATE attribut SET est_visible=false WHERE id='.$pdo->quote($id);
			$pdo->query($sql);
			$sql = 'UPDATE valeur_attribut SET est_visible=false WHERE ref_attribut='.$pdo->quote($id);
			$pdo->query($sql);
		}
		else {
			return new \SoapFault('Server', '[SUATTR003] Paramètres invalides.');
		}
	
		return '';
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
	public function setAttributAction($nom, $lignesProduits, $attributs, $id)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SA001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom) || !is_string($lignesProduits) || !is_string($attributs) || !is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[SA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service

		$tabLgProduit = json_decode($lignesProduits);
		$tabAttributs = json_decode($attributs);
		if ($tabLgProduit === NULL || $tabAttributs === NULL || empty($nom)) // On vérif qu'on arrive à decoder le json 
			return new \SoapFault('Server', '[SA003] Paramètres invalides, JSON attendu.');
		
		if (strpos($nom, '_') !== false) {
			return new \SoapFault('Server', '[SA008] Le caractère \'_\' n\'est pas autorisé dans les attributs ('.$nom.').');
		}
		// On vérifie qu'il n'y a pas de caractères génants ('_')
		foreach ($tabAttributs as $attribut) {
			if (strpos($attribut, '_') !== false)
				return new \SoapFault('Server', '[SA007] Le caractère \'_\' n\'est pas autorisé dans les attributs ('.$attribut.').');
		}

		// Si on veut modifier un attribut existant
		if ($id !== 0) {
			// Formation de la requete SQL
			$sql = 'SELECT nom FROM attribut WHERE id=\'' . (int)$id . '\'';
			$count = $pdo->query($sql);

			// Si l'attribut n'existe pas
			if ($count->rowCount() === 0) {
				return new \SoapFault('Server', '[SA004] L\'attribut choisi n\'existe pas. Peut-être vouliez-vous ajouter un attribut ?');
			}

			$sql = 'UPDATE attribut SET nom=' . $pdo->quote($nom) . ' WHERE id=\'' . (int)$id . '\''; // On modifie le nom de l'attribut
			$count = $pdo->query($sql);

			$sql = 'UPDATE valeur_attribut SET est_visible=FALSE WHERE ref_attribut=\'' . (int)$id . '\' AND est_visible=TRUE'; // On supprime toutes les valeurs de cet attribut
			$count = $pdo->query($sql);

			// Insertion des valeurs d'attribut possible
			foreach ($tabAttributs as $libelle) {
				if (!empty($libelle)) {
					$sql = 'INSERT INTO valeur_attribut (ref_attribut, libelle, est_visible) VALUES (\'' . (int)$id . '\', ' . $pdo->quote($libelle) . ', TRUE)';
					$count = $pdo->exec($sql);
				}
			}

			$sql = 'DELETE FROM ligne_produit_a_pour_attribut WHERE ref_attribut=\'' . (int)$id . '\''; // On supprime toutes les valeurs de cet attribut
			$count = $pdo->query($sql);

			foreach ($tabLgProduit as $produit) {
				$sql = 'SELECT id FROM ligne_produit WHERE nom=' . $pdo->quote($produit);
				$resultat = $pdo->query($sql);
				if ($resultat->rowCount() === 0) // Si pas de résultat, la ligne produit n'existe pas et on continue
					continue;

				// On récup l'id de la ligne produit
				foreach ($resultat as $row) {
					$idLigneProduit = $row['id'];
				}

				$sql = 'INSERT INTO ligne_produit_a_pour_attribut (ref_ligne_produit, ref_attribut)' .
					'VALUES (' . $pdo->quote($idLigneProduit) . ', ' . $pdo->quote($id) . ')';
				$count = $pdo->exec($sql);
			}
		} else { // On ajoute un attribut
			$sql = 'SELECT nom FROM attribut WHERE nom=' . $pdo->quote($nom);
			$resultat = $pdo->query($sql);
			if ($resultat->rowCount() !== 0)
				return new \SoapFault('Server', '[SA005] Le nom que vous avez choisi existe déjà. Peut-être vouliez-vous modifier un attribut existant ?');

			$sql = 'INSERT INTO attribut (nom, est_visible) VALUES (' . $pdo->quote($nom) . ', TRUE)';
			$count = $pdo->exec($sql);
			if ($count !== 1) { // Si problème insertion
				return new \SoapFault('Server', '[SA006] Erreur lors de l\'enregistrement des données');
			}
			$idAttribut = $pdo->lastInsertId(); // On recup l'id de l'attribut créé

			// Insertion des valeurs d'attribut possible
			foreach ($tabAttributs as $libelle) {
				$sql = 'INSERT INTO valeur_attribut (ref_attribut, libelle, est_visible) VALUES (' . $idAttribut . ', ' . $pdo->quote($libelle) . ', TRUE)';
				$count = $pdo->exec($sql);
			}

			// Insertion des lignes produits dans la table ligne_produit_a_pour_attribut
			foreach ($tabLgProduit as $produit) {
				$sql = 'SELECT id FROM ligne_produit WHERE nom=' . $pdo->quote($produit);
				$resultat = $pdo->query($sql);
				if ($resultat->rowCount() === 0) // Si pas de résultat, la ligne produit n'existe pas et on continue
					continue;

				// On récup l'id de la ligne produit
				foreach ($resultat as $row) {
					$idLigneProduit = $row['id'];
				}

				$sql = 'INSERT INTO ligne_produit_a_pour_attribut (ref_ligne_produit, ref_attribut)' .
					'VALUES (' . $pdo->quote($idLigneProduit) . ', ' . $pdo->quote($idAttribut) . ')';
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
	public function getAttributAction($nom, $idLigneProduit, $idAttribut, $avecValeurAttribut, $avecLigneProduit)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GA001] Vous n\'avez pas les droits nécessaires.');

		if (!is_int($idLigneProduit) || !is_int($idAttribut) || !is_bool($avecValeurAttribut)
			|| !is_bool($avecLigneProduit) || !is_string($nom)
		) // Vérif des arguments
			return new \SoapFault('Server', '[GA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo();
		$result = array(); // Tableau contenant le résultat

		// Si on a pas de critère c'est qu'on veut tout les attributs et on ne va pas récupérer les valeurs ni les lignes produits
		if ($idLigneProduit === 0 && $idAttribut === 0) {
			if (true === $avecValeurAttribut) {
				$sql = 'SELECT a.id AS aid, a.nom, v.libelle, a.est_visible, GROUP_CONCAT(lp.nom SEPARATOR ", ") AS ligne_produit FROM attribut a
				        JOIN valeur_attribut v ON v.ref_attribut=a.id
						JOIN ligne_produit_a_pour_attribut lpapva ON lpapva.ref_attribut = a.id
						JOIN ligne_produit lp ON lpapva.ref_ligne_produit = lp.id
						WHERE a.est_visible = TRUE AND v.est_visible = TRUE ';

				if (!empty($nom)) {
					$nom = '%' . $nom . '%';
					$sql .= ' AND a.nom LIKE '.$pdo->quote($nom).' OR v.libelle LIKE '.$pdo->quote($nom);
				}

				$sql .= ' GROUP BY libelle ORDER BY nom ASC';
				
				$tabAttributs = array();
				$dernierNom = '';
				$dernierId = 0;
				$dernierLigne = '';
				foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
					if ($dernierNom !== $row['nom']) {
						$ligne = array('id' => $dernierId, 'nom' => $dernierNom, 'attributs' => $tabAttributs, 'ligne_produit' => $dernierLigne);
						array_push($result, $ligne);
						$tabAttributs = array();
					}
					//$dernierLibelle = $row['libelle'];
					$dernierNom = $row['nom'];
					$dernierId = $row['aid'];
					$dernierLigne = $row['ligne_produit'];
					array_push($tabAttributs, $row['libelle']);
				}
				$ligne = array('id' => $dernierId, 'nom' => $dernierNom, 'attributs' => $tabAttributs, 'ligne_produit' => $dernierLigne);
				array_push($result, $ligne);
			} 
			else {
				$sql = 'SELECT id, nom FROM attribut a ';

				if (!empty($nom)) {
					$nom = '%' . $nom . '%';
					$sql .= 'WHERE attribut.est_visible = TRUE AND a.nom LIKE ' . $pdo->quote($nom);
				}
				foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
					$ligne = array('id' => $row['id'], 'nom' => $row['nom']);
					array_push($result, $ligne);
				}
			}
		} // Si on cherche un attribut avec un id spécifique
		else if ($idAttribut !== 0) {
			if (true === $avecValeurAttribut) { // Si on veut les valeurs d'attributs, on les récupère
				$sql = 'SELECT a.id, a.nom, v.libelle FROM attribut a ';
				$sql .= 'JOIN valeur_attribut v ON v.ref_attribut=a.id ';
				$sql .= 'WHERE v.est_visible = TRUE AND a.id=' . (int)$idAttribut;

				$result['attribut'] = array();
				foreach ($pdo->query($sql) as $row) { // On ajoute tous les attributs à la réponse
					$result['nom'] = $row['nom'];
					$result['id'] = $row['id'];
					$ligne = array('valeurAttr' => $row['libelle']);
					array_push($result['attribut'], $ligne);
				}
			}
			if (true === $avecLigneProduit) { // Si on veut les lignes produits, on les récupère
				$sql = 'SELECT l.nom AS nomLigneProduit FROM attribut a ';
				$sql .= 'JOIN ligne_produit_a_pour_attribut lp ON a.id=lp.ref_attribut ';
				$sql .= 'JOIN ligne_produit l ON ref_ligne_produit=l.id ';
				$sql .= 'WHERE a.id=' . (int)$idAttribut;

				$result['ligneProduit'] = array();
				foreach ($pdo->query($sql) as $row) { // On ajoute toutes les lignes produit à la réponse
					$ligne = array('nomLigneProduit' => $row['nomLigneProduit']);
					array_push($result['ligneProduit'], $ligne);
				}
			}
		}
		return json_encode($result);
	}

	/**
	 * Permet de rechercher un contact depuis n'importe quel critère.
	 *
	 * @param $codeBarre Le code barre du produit recherché.
	 *
	 * @Soap\Method("getContactFromEverything")
	 * @Soap\Param("critere",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getContactFromEverythingAction($critere) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT')) && 
			!($this->container->get('user_service')->isOk('ROLE_EMPLOYE'))) // On check les droits
			return new \SoapFault('Server', '[GCFE001] Vous n\'avez pas les droits nécessaires.');
		
		if (!is_string($critere) || empty ($critere)) // Vérif des arguments
			return new \SoapFault('Server', '[GCFE002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo();
		$reponse = array();
		
		$sql = 'SELECT id, nom, prenom, date_naissance, civilite, email, telephone_portable FROM contact
				WHERE CONCAT_WS ("|", IFNULL(nom, ""), IFNULL(prenom, ""), IFNULL(date_naissance, ""),
								IFNULL(email, ""), IFNULL(telephone_portable, "") )
								REGEXP '.$pdo->quote($critere);
		
		$resultat = $pdo->query($sql);
		
		foreach ($resultat as $row) {
			$ligne = array();
			$ligne['id'] = $row['id'];
			$ligne['nom'] = $row['nom'];
			$ligne['prenom'] = $row['prenom'];
			$ligne['date_naissance'] = $row['date_naissance'];
			$ligne['civilite'] = $row['civilite'];
			$ligne['email'] = $row['email'];
			$ligne['telephone_portable'] = $row['telephone_portable'];
			array_push($reponse, $ligne);
		}
		
		return json_encode($reponse);
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
	public function getPrixFromCodeBarreAction($codeBarre)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GPFCB001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($codeBarre)) // Vérif des arguments
			return new \SoapFault('Server', '[GPFCB002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo();
		$sql = 'SELECT montant_client, montant_fournisseur FROM prix JOIN article ON ref_article=article.id WHERE code_barre=' . $pdo->quote($codeBarre);
		$resultat = $pdo->query($sql);
		$res = array();

		//$prix = 0;
		foreach ($resultat as $row) {
			$res['montant_client'] = $row["montant_client"];
			$res['montant_fournisseur'] = $row['montant_fournisseur'];
		}

		return json_encode($res);
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
	public function ajoutProduitAction($nom, $ligneProduit)
	{
		//on teste si l'utilisateur a les droits pour accéder à cette fonction
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[ALP001] Vous n\'avez pas les droits nécessaires.');
		if (!is_string($nom) || !is_string($ligneProduit) || $nom=='') // Vérif des arguments
			return new \SoapFault('Server', '[ALP002] Paramètres invalides.');

		try {

			$pdo = $this->container->get('bdd_service')->getPdo();

			//on verifie si il y a deja le produit
			$sql = 'SELECT * FROM produit JOIN ligne_produit ON produit.ref_ligne_produit = ligne_produit.id
			WHERE produit.nom=' . $pdo->quote($nom) . ' AND ligne_produit.nom=' . $pdo->quote($ligneProduit) . ' AND produit.est_visible=true ';

			//requête qui permet de récupérer l'identifiant de la ligne produit
			$sql_lp = 'SELECT * FROM ligne_produit WHERE nom=' . $pdo->quote($ligneProduit) . '';

			//on exécute les requêtes
			$resultat = $pdo->query($sql);

			if ($resultat->rowCount() == 0) {

				//on recupère l'identifiant de la ligne produit
				foreach ($pdo->query($sql_lp) as $row) {
					$id = $row["id"];
				}
				//on insert le produit pour la ligne de produit $ligneProduit
				$sql = 'INSERT INTO produit(nom,ref_ligne_produit)VALUES(' . $pdo->quote($nom) . ',' . $pdo->quote($id) . ');';
				$pdo->query($sql);

				return "OK";
			}

		} catch (Exception $e) {
			return new \SoapFault("Server", "[ALP003] La ligne produit existe déjà");
		}
	}
	
	/**
	 * @Soap\Method("supprProduit")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function supprProduitAction($id){
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SP001] Vous n\'avez pas les droits nécessaires.');
		
		if (!is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[SP002] Paramètres invalides.');
		
		//on récupere l'objet pdo connecté à la base du logiciel
		$pdo = $this->container->get('bdd_service')->getPdo();
		
		$sql ='UPDATE produit SET est_visible=0 WHERE id='.(int)$id.';';
		
		$pdo->query($sql);
		
		return "OK";
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
	 * @Soap\Param("attribut",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getProduitAction($count, $offset, $nom, $ligneproduit, $attribut)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GP001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom) || !is_int($offset) || !is_int($count) || !is_string($ligneproduit)) // Vérif des arguments
			return new \SoapFault('Server', '[GP002] Paramètres invalides.');

		//on récupere l'objet pdo connecté à la base du logiciel
		$pdo = $this->container->get('bdd_service')->getPdo();


		// Formation de la requete SQL selon les paramètres donnés
		//$sql = 'SELECT ligne_produit.nom as "lp_nom",produit.id as "p_id", produit.nom as "p_nom" FROM produit JOIN ligne_produit ON produit.ref_ligne_produit=ligne_produit.id ';

		$sql='select ligne_produit.nom as "lp_nom", produit.id as "p_id", produit.nom as "p_nom"  from produit
join ligne_produit on ligne_produit.id = produit.ref_ligne_produit
left outer join article on article.ref_produit = produit.id
left outer join ligne_produit_a_pour_attribut on ligne_produit.id = ligne_produit_a_pour_attribut.ref_ligne_produit
left outer join attribut on attribut.id = ligne_produit_a_pour_attribut.ref_attribut
left outer join article_a_pour_val_attribut on article_a_pour_val_attribut.ref_article=article.id
left outer join valeur_attribut on valeur_attribut.id = article_a_pour_val_attribut.ref_val_attribut ';

		if (!empty($nom) && !empty($ligneproduit) && !empty($attribut)){
			$nom = '%'.$nom.'%';
			$ligneproduit= '%'.$ligneproduit.'%';
			$attribut = '%'.$attribut.'%';
			$sql .= 'WHERE ligne_produit.est_visible=\'1\' AND produit.est_visible=\'1\' AND produit.nom LIKE ' . $pdo->quote($nom) . ' AND ligne_produit.nom LIKE ' . $pdo->quote($ligneproduit) . '
			 AND (attribut.nom LIKE '.$pdo->quote($attribut).' OR valeur_attribut.libelle LIKE '.$pdo->quote($attribut).')';
		}

		elseif (empty($nom) && !empty($ligneproduit) && empty($attribut)){
			$ligneproduit= '%'.$ligneproduit.'%';
			$sql .= 'WHERE ligne_produit.est_visible=\'1\' AND produit.est_visible=\'1\' AND ligne_produit.nom LIKE ' . $pdo->quote($ligneproduit) . '';
		}
		elseif (!empty($nom) && empty($ligneproduit) && empty($attribut)){
			$nom = '%'.$nom.'%';
			$sql .= 'WHERE ligne_produit.est_visible=\'1\' AND produit.est_visible=\'1\' AND produit.nom LIKE ' . $pdo->quote($nom) . '';
		}
		elseif (empty($nom) && empty($ligneproduit) && !empty($attribut)){
			$attribut = '%'.$attribut.'%';
			$sql .= 'WHERE ligne_produit.est_visible=\'1\' AND produit.est_visible=\'1\' AND (attribut.nom LIKE '.$pdo->quote($attribut).' OR valeur_attribut.libelle LIKE '.$pdo->quote($attribut).')';
		}
		elseif (!empty($nom) && !empty($ligneproduit) && empty($attribut)){
			$ligneproduit= '%'.$ligneproduit.'%';
			$nom = '%'.$nom.'%';
			$sql .= 'WHERE ligne_produit.nom LIKE ' . $pdo->quote($ligneproduit) . '
			AND ligne_produit.est_visible=\'1\' AND produit.est_visible=\'1\' AND produit.nom LIKE '.$pdo->quote($nom).'';
		}
		elseif (empty($nom) && !empty($ligneproduit) && !empty($attribut)){
			$ligneproduit = '%'.$ligneproduit.'%';
			$attribut = '%'.$attribut.'%';
			$sql .= 'WHERE ligne_produit.nom LIKE ' . $pdo->quote($ligneproduit) . '
			AND ligne_produit.est_visible=\'1\' AND produit.est_visible=\'1\' AND (attribut.nom LIKE '.$pdo->quote($attribut).' OR valeur_attribut.libelle LIKE '.$pdo->quote($attribut).')';
		}
		elseif (!empty($nom) && empty($ligneproduit) && !empty($attribut)){
			$nom = '%'.$nom.'%';
			$sql .= 'WHERE produit.nom LIKE ' . $pdo->quote($nom) . '
			AND ligne_produit.est_visible=\'1\' AND produit.est_visible=\'1\' AND (attribut.nom LIKE '.$pdo->quote($attribut).' OR valeur_attribut.libelle LIKE '.$pdo->quote($attribut).')';
		}
		else{
			$sql .= 'WHERE ligne_produit.est_visible=\'1\' AND produit.est_visible=\'1\'';
		}
		$sql.= 'group by ligne_produit.nom, produit.id, produit.nom;';
		if ($offset != 0) {
			$sql .= 'ORDER BY ligne_produit.nom ASC LIMIT ' . (int)$offset;
			if ($count != 0)
				$sql .= ',' . (int)$count;
		} else {
			$sql .= 'ORDER BY ligne_produit.nom ASC';
		}

		//exécution de la requête
		$resultat = array();

		//on créé le tableau de retour à partir de la requête
		foreach ($pdo->query($sql) as $ligne) {
			$row = array('lp' => $ligne['lp_nom'], 'p_id' => $ligne['p_id'], 'p' => $ligne['p_nom']);
			array_push($resultat, $row);
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
	public function modifProduitAction($nom_lp, $nom_p, $id_p)
	{

		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MP001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom_lp) || !is_int($id_p) || !is_string($nom_p)) // Vérif des arguments
			return new \SoapFault('Server', '[MP002] Paramètres invalides.');

		//on récupere l'objet pdo connecté à la base du logiciel
		$pdo = $this->container->get('bdd_service')->getPdo();

		$sql_recup_lp_id = 'SELECT ligne_produit.id "lp_id" FROM ligne_produit WHERE ligne_produit.nom=' . $pdo->quote($nom_lp) . '';

		foreach ($pdo->query($sql_recup_lp_id) as $ligne_lp) {
			$id_lp = $ligne_lp['lp_id'];
		}
		// Formation de la requete SQL selon les paramètres donnés
		$sql = 'UPDATE produit SET nom=' . $pdo->quote($nom_p) . ', ref_ligne_produit=' . $pdo->quote($id_lp) . ' WHERE id=' . $pdo->quote($id_p) . '';

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
	public function getMenuAction()
	{
		// Verifie le role de l'utilisateur connecte
		// Si il est gerant
		if ($this->container->get('user_service')->isOk('ROLE_GERANT')) {
			$tableau_menu = array(
				array('menu' => 'caisse','sous_menu' => array()),
				array('menu' => 'client','sous_menu' => array('Informations client', 'Statistiques','Anniversaires')),
				array('menu' => 'evenement','sous_menu' => array()),
				array('menu' => 'fournisseur','sous_menu' => array('Fournisseurs','Historique des commandes complètes','Commandes','Réception')),
				array('menu' => 'produit','sous_menu' => array('Lignes produits','Attributs','Produits','Articles','Stock','Inventaire', 'Inventaire complet', 'Génération de codes barres')),
				array('menu' => 'vente','sous_menu' => array('Moyens de paiement','Statistiques','Factures','Retour client')));
			return json_encode($tableau_menu);
		} // Si il est employe
		else if ($this->container->get('user_service')->isOk('ROLE_EMPLOYE')) {
			
		} else { // Si l'utilisateur n'est pas connecté
			return new \SoapFault('Server', '[GM001] Vous n\'avez pas les droits nécessaires.');
		}
	}

	/**
	 * Permet de retourner tous les produits en fonction de la ligne de produit
	 *
	 * @Soap\Method("getProduitFromLigneProduit")
	 * @Soap\Param("LigneProduit",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getProduitFromLigneProduitAction($LigneProduit)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GPFLP001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($LigneProduit)) // Vérif des arguments
			return new \SoapFault('Server', '[GPFLP002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		// Si la ligne de produit est differente de vide
		if (!empty($LigneProduit)) {
			// Recuperer tous les produits de la ligne produit
			$sql_recupere_produit = 'SELECT nom FROM alba.produit WHERE est_visible = 1
					 AND ref_ligne_produit = (SELECT id FROM alba.ligne_produit WHERE nom=' . $pdo->quote($LigneProduit) . ')';
		} else {
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
	 */
	public function getStockAction($LigneProduit, $Produit, $Article)
	{

		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GS001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($LigneProduit) || !is_string($Produit) || !is_string($Article)) // Vérif des arguments
			return new \SoapFault('Server', '[GS002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		$requete_stock = 'SELECT l.nom as ligne_produit_nom,p.nom as produit_nom,a.code_barre article_code_barre,a.id as id_article FROM alba.ligne_produit l
						INNER JOIN alba.produit p ON p.ref_ligne_produit = l.id
						INNER JOIN alba.article a ON a.ref_produit = p.id';

		// Si l'article est renseigner. Pas de else if car l'utilisateur peut tres bien
		// selection une ligne produit puis finalement s�lectionner biper un artcile.
		// et on donnne la priorit� a l'article!

		if (!empty($Article)) {
			$requete_stock = $requete_stock . ' WHERE a.code_barre = ' . $pdo->quote($Article) . ' AND a.est_visible=1';
		} //Si le parametre ligne de produit n'est pas vide
		else if (!empty($LigneProduit)) {
			// On verifie si l'utilisateur a selectionner un produit
			// Si oui on fait la recherche par rapport a ce produit et non a la ligne produit
			if (!empty($Produit)) {
				$requete_stock = $requete_stock . ' WHERE p.nom = ' . $pdo->quote($Produit) . ' AND p.est_visible=1 ';
			} // sinon on recherche par la ligne produit
			else {
				$requete_stock = $requete_stock . ' WHERE l.nom = ' . $pdo->quote($LigneProduit) . ' AND l.est_visible=1';
			}
		}

		$requete_stock = $requete_stock . ' ORDER BY ligne_produit_nom,produit_nom ASC';

		foreach ($pdo->query($requete_stock) as $row_ligne) {
			$sql_quantite_article = 'SELECT SUM(quantite_mouvement) as total_mouvement FROM alba.mouvement_stock
															WHERE ref_article = ' . $row_ligne['id_article'] . ' AND date_mouvement >= (SELECT date_mouvement FROM alba.mouvement_stock
															WHERE ref_article = ' . $row_ligne['id_article'] . '
															AND est_inventaire = 1
															AND est_visible = 1
															order by date_mouvement desc limit 1)';
			// On parcourt les mouvements de stock de l'article
			foreach ($pdo->query($sql_quantite_article) as $row_quantite) {
				$ligne = array('ligne_produit' => $row_ligne['ligne_produit_nom'], 'produit' => $row_ligne['produit_nom'], 'article' => $row_ligne['article_code_barre'], 'quantite' => $row_quantite['total_mouvement']);
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
	 */
	public function getAllLigneProduitAction()
	{

		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GALP001] Vous n\'avez pas les droits nécessaires.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		$requete_tous_les_produits = 'SELECT nom as ligne_produit_nom FROM alba.ligne_produit WHERE est_visible = 1 ORDER BY nom ASC';

		foreach ($pdo->query($requete_tous_les_produits) as $row) {
			$ligne = array('nom_ligne_produit' => $row['ligne_produit_nom']);
			array_push($result, $ligne);
		}
		return json_encode($result);
	}

	/**
	 * Permet de retourner toutes les factures.
	 *
	 * @Soap\Method("getAllFacture")
	 * @Soap\Param("date",phpType="string")
	 * @Soap\Param("client",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getAllFactureAction($date,$client){
		
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GAF001] Vous n\'avez pas les droits nécessaires.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
	
		if (!is_string($date) || !is_string($client) ) // Vérif des arguments
			return new \SoapFault('Server', '[GAF002] Paramètres invalides.');
		
		
		$requete_toutes_les_lignes_factures = 'SELECT id_facture, date_de_facture, nom_contact,
						SUM(CASE 
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) AS montant
				        FROM( SELECT * FROM ventes_contact';
				      
		if($date !== ''){
			$requete_toutes_les_lignes_factures = $requete_toutes_les_lignes_factures .' WHERE date_de_facture > ' . $pdo->quote($date) . '';
			if($client !== ''){
				$client = $pdo->quote('%'.$client.'%');
				$requete_toutes_les_lignes_factures = $requete_toutes_les_lignes_factures .' AND nom_contact LIKE ' . $client . '';
			}
		}
		else {
			if($client !== ''){
				$client = $pdo->quote('%'.$client.'%');
				$requete_toutes_les_lignes_factures = $requete_toutes_les_lignes_factures .' WHERE nom_contact LIKE ' . $client . '';
			}
		}
		
		
		//On ajoute a la requete la fin
		$requete_toutes_les_lignes_factures = $requete_toutes_les_lignes_factures . ' ) t GROUP BY id_facture ORDER BY id_facture DESC';
		
		//return new \SoapFault('Server', '[GAF002] '.$requete_toutes_les_lignes_factures);
		
		foreach ($pdo->query($requete_toutes_les_lignes_factures) as $row) {
			$ligne = array('numero' => $row['id_facture'],
					'client'=>$row['nom_contact'],
					'date'=>$row['date_de_facture'],
					'montant'=>$row['montant']);
			array_push($result, $ligne);
		}
		
		return json_encode($result);
	}
	
	/**
	 * Permet de retourner toutes les factures en filtrant avec certains critere.
	 * @param $dateDebut Chercher les factures a partir de cette date.
	 * @param $dateFin Chercher les factures jusqu'a cette date.
	 * @param $ligneProduit Chercher les factures ayant au moins un produit de cette ligne produit.
	 * @param $produit Chercher les factures ayant au moins un article de ce type de produit.
	 * @param $client Chercher un article ayant pour client $client.
	 *
	 * @Soap\Method("getFactureFromCritere")
	 * @Soap\Param("dateDebut",phpType="string")
	 * @Soap\Param("dateFin",phpType="string")
	 * @Soap\Param("ligneProduit",phpType="string")
	 * @Soap\Param("produit",phpType="string")
	 * @Soap\Param("client",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getFactureFromCritereAction($dateDebut, $dateFin, $ligneProduit, $produit, $client) {
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GAFC001] Vous n\'avez pas les droits nécessaires.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
	
		if (!is_string($dateDebut) || !is_string($dateFin) || !is_string($ligneProduit) 
			|| !is_string($produit) || !is_string($client)) // Vérif des arguments
			return new \SoapFault('Server', '[GAFC002] Paramètres invalides.');
	
	
		$sql = 'SELECT id_facture, date_de_facture, nom_contact,
						SUM(CASE
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) AS montant
				        FROM( SELECT id_facture, nom_contact, DATE_FORMAT(date_de_facture, "%d/%m/%Y à %H:%i") AS date_de_facture, type_reduction, 
									 reduction_article,  nb_article, montant_client 
				 			  FROM ventes_contact 
							  JOIN produit p ON article_ref_produit = p.id
							  JOIN ligne_produit lp ON p.ref_ligne_produit = lp.id ';
	
		if ($dateDebut !== '' || $dateFin !== '' || $ligneProduit !== '' || $produit !== '' || $client !== '') {
			$sql .= ' WHERE 1=1 '; // Juste pour eviter de traiter tous les cas ou on commence le where par l'un ou par l'autre
			
			if($dateDebut !== '')
				$sql = $sql .' AND date_de_facture >= ' . $pdo->quote($dateDebut) . ' ';
			if($dateFin !== '')
				$sql = $sql .' AND date_de_facture <= ' . $pdo->quote($dateFin) . ' ';
			if($ligneProduit !== '')
				$sql = $sql .' AND lp.nom LIKE ' . $pdo->quote('%'.$ligneProduit.'%') . ' ';
			if($produit !== '')
				$sql = $sql .' AND p.nom LIKE ' . $pdo->quote('%'.$produit.'%') . ' ';
			if($client !== '')
				$sql = $sql .' AND nom_contact LIKE ' . $pdo->quote('%'.$client.'%') . ' ';
		}
		
	
		// On ajoute a la requete la fin
		$sql = $sql . ' ) t GROUP BY id_facture ORDER BY id_facture DESC';
			
		foreach ($pdo->query($sql) as $row) {
			$ligne = array('numero' => $row['id_facture'],
					'client'=>$row['nom_contact'],
					'date'=>$row['date_de_facture'],
					'montant'=>$row['montant']);
			array_push($result, $ligne);
		}
		return json_encode($result);
	}

	/**
	 * Permet de retourner les details d'une seul facture
	 *
	 * @Soap\Method("getDetailFromOneFacture")
	 * @Soap\Param("numero",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function getDetailFromOneFactureAction($numero){
	
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GDFOF001] Vous n\'avez pas les droits nécessaires.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
	
		if (!is_int($numero) || $numero < 0) // Vérif des arguments
			return new \SoapFault('Server', '[GDFOF002] Paramètres invalides.');
	
	
		$requete_detail_factures = 'SELECT id_facture ,nom_produit , date_de_facture, UPPER(nom_contact) as nom_contact_maj, prenom_contact, nom_article ,article_id , nb_article, montant_client ,reduction_article,
						SUM(CASE
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) AS montant,adresse_numero,adresse_rue,adresse_code_postal,adresse_ville,adresse_pays,nom_moyen_paiement
				        FROM(
				        SELECT 
							   lf.id as "ligne_facture_id",
				               a.id as "article_id",
							   a.code_barre as "nom_article",
				               px.montant_client as "prix_id",
				               f.id as "id_facture" ,
				               f.date_facture as "date_de_facture",
				               c.nom as "nom_contact",
							   c.prenom as "prenom_contact",
							   c.id as "id_contact",
							   ad.num_voie as "adresse_numero",
							   ad.voie as "adresse_rue",
							   ad.code_postal as "adresse_code_postal",
							   ad.ville as "adresse_ville",
							   ad.pays as "adresse_pays",
				               r.reduction as "reduction_article",
				               m.quantite_mouvement as "nb_article",
				               r.type_reduction as "type_reduction",
				               px.montant_client as "montant_client",
							   pt.nom as "nom_produit",
							   mp.nom as "nom_moyen_paiement"
				      
				        FROM facture f
						JOIN ligne_facture lf ON lf.ref_facture = f.id 
						JOIN mouvement_stock m ON lf.ref_mvt_stock = m.id
						JOIN article a ON m.ref_article = a.id
				        JOIN prix px ON px.ref_article = a.id AND px.id = 
                        (SELECT MAX(prix.id) FROM prix WHERE prix.date_modif<f.date_facture AND prix.ref_article=a.id)
				        JOIN produit pt ON a.ref_produit = pt.id
				        LEFT OUTER JOIN remise r ON lf.ref_remise = r.id
				        LEFT OUTER JOIN contact c ON f.ref_contact = c.id
						LEFT OUTER JOIN adresse ad ON c.id = ad.ref_contact AND ad.ref_type_adresse = 1
                        LEFT OUTER JOIN moyen_paiement mp ON f.ref_moyen_paiement = mp.id 
						
						WHERE f.id = '.(int)$numero.'
						 ) t GROUP BY ligne_facture_id ORDER BY id_facture ASC';
	
		foreach ($pdo->query($requete_detail_factures) as $row) {
			$nombre_article = (-1*$row['nb_article']);
			$ligne = array('numero_facture' => $row['id_facture'],
					'date_facture'=>$row['date_de_facture'],
					'nom_produit'=>$row['nom_produit'],
					'nom_client'=>$row['nom_contact_maj'],
					'prenom_client'=>$row['prenom_contact'],
					'adresse_numero'=>$row['adresse_numero'],
					'adresse_rue'=>$row['adresse_rue'],
					'adresse_code_postal'=>$row['adresse_code_postal'],
					'adresse_ville'=>$row['adresse_ville'],
					'adresse_pays'=>$row['adresse_pays'],
					'nom_article'=>$row['nom_article'],
					'nombre_article'=>$nombre_article,
					'prix_article'=>$row['montant_client'],
					'reduction_article'=>$row['reduction_article'],
					'nom_moyen_paiement'=>$row['nom_moyen_paiement'],
					'montant_facture'=>$row['montant']);
			array_push($result, $ligne);
		}
		return json_encode($result);
	}
	
	
	
	/**
	 * Permet de retourner tous les anniversaires du jour si il n'y a pas 
	 * de date pass� en parametre, sinon les anniversaires depuis la date pass� en parametre
	 * jusqu'a aujourd'hui
	 *
	 * @Soap\Method("getAnniversaire")
	 * @Soap\Param("mois",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getAnniversaireAction($mois){
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GAN001] Vous n\'avez pas les droits nécessaires.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
		
		if (!is_string($mois) ) // Vérif des arguments
			return new \SoapFault('Server', '[GAN002] Paramètres invalides.');
		
		if($mois !== ''){
			$requete_date_anniversaire = 'SELECT civilite,nom,prenom,DATE_FORMAT(date_naissance,"%d/%m/%Y") as date_naissance,email,abs(timestampdiff(YEAR,curdate(),date_naissance)) as age FROM alba.contact
			WHERE month(date_naissance) = '.$pdo->quote($mois).'';
			
			foreach ($pdo->query($requete_date_anniversaire) as $row) {
				$ligne = array(
						'client_civilite' => $row['civilite'],
						'client_nom' => $row['nom'],
						'client_prenom' => $row['prenom'],
						'client_date' => $row['date_naissance'],
						'client_email' => $row['email'],
						'client_age' => $row['age']
						);
				array_push($result, $ligne);
			}
		}
		else if ($mois === '') {
			$requete_date_anniversaire = 'SELECT civilite,nom,prenom,DATE_FORMAT(date_naissance,"%d/%m/%Y") as date_naissance,email,abs(timestampdiff(YEAR,curdate(),date_naissance)) as age FROM alba.contact
			WHERE month(date_naissance) = month(now())
			AND day(date_naissance) = day(now())';
				
			foreach ($pdo->query($requete_date_anniversaire) as $row) {
				$ligne = array(
						'client_civilite' => $row['civilite'],
						'client_nom' => $row['nom'],
						'client_prenom' => $row['prenom'],
						'client_date' => $row['date_naissance'],
						'client_email' => $row['email'],
						'client_age' => $row['age']
				);
				array_push($result, $ligne);
			}
		}
		else {
			return new \SoapFault('Server', '[GAN003] Paramètres invalides.');
		}
		return json_encode($result);
		
	}	

	/**
	 * Permet de retourner tous les moyen de paiement accepter par le magasin
	 *
	 * @Soap\Method("getAllModePaiement")
	 * @Soap\Result(phpType = "string")
	 */
	public function getAllModePaiementAction(){
		
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[GAMP001] Vous n\'avez pas les droits nécessaires.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();
		
		$requete_mode_paiement = 'SELECT id,nom FROM alba.moyen_paiement WHERE est_visible = 1 ORDER BY nom ASC';
		
		foreach ($pdo->query($requete_mode_paiement) as $row) {
			$ligne = array(
					'paiement_id' => $row['id'],
					'paiement_nom' => $row['nom'],
			);
			array_push($result, $ligne);
		}
		return json_encode($result);
	}
	
	/**
	 * Permet de retourner tous les moyen de paiement accepter par le magasin
	 *
	 * @Soap\Method("modifierModePaiement")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifierModePaiementAction($id,$nom){
	
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[MMP001] Vous n\'avez pas les droits nécessaires.');
	
		if (!is_int($id) || !is_string($nom) ) // Vérif des arguments
			return new \SoapFault('Server', '[MMP002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service	
		
		if($id != ''){
			$requete_modifier_mode_paiement = 'UPDATE alba.moyen_paiement SET nom='.$pdo->quote($nom).' WHERE id='.$pdo->quote($id).' ';
			$pdo->query($requete_modifier_mode_paiement);
			return 'OK';
		}
		else{
			return new \SoapFault('Server', '[MMP003] Paramètres invalides.');
		}
	}
	
	/**
	 * Permet de retourner tous les moyen de paiement accepter par le magasin
	 *
	 * @Soap\Method("supprimerModePaiement")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function supprimerModePaiementAction($id){
	
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[SMP001] Vous n\'avez pas les droits nécessaires.');
	
		if (!is_int($id) ) // Vérif des arguments
			return new \SoapFault('Server', '[SMP002] Paramètres invalides.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
	
		if($id != ''){
			$requete_supprimer_mode_paiement = 'UPDATE alba.moyen_paiement SET est_visible = 0  WHERE id='.$pdo->quote($id).' ';
			$pdo->query($requete_supprimer_mode_paiement);
			return 'OK';
		}
		else{
			return new \SoapFault('Server', '[SMP003] Paramètres invalides.');
		}
	}
	
	/**
	 * Permet d'inserer un nouveau mode de paiement
	 *
	 * @Soap\Method("insererModePaiement")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function insererModePaiementAction($nom){
	
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server','[IMP001] Vous n\'avez pas les droits nécessaires.');
	
		if (!is_string($nom) ) // Vérif des arguments
			return new \SoapFault('Server', '[IMP002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		
		if($nom != ''){
			$requete_mode_paiement = 'INSERT INTO alba.moyen_paiement(nom) VALUES('.$pdo->quote($nom).')';
			$pdo->query($requete_mode_paiement);
			return 'OK';
		}
		else{
			return new \SoapFault('Server', '[IMP003] Paramètres invalides.');
		}
	}
	
	/**
	 * @Soap\Method("supprFournisseur")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function supprFournisseurAction($id){
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
	
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SF001] Vous n\'avez pas les droits nécessaires.');
	
	
	
		if (!is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[SFC002] Paramètres invalides.');
	
	
	
		$sql = 'UPDATE fournisseur SET est_visible=0 WHERE id='.$pdo->quote($id).';';
	
		//return new \SoapFault('Server', 'coucou');
	
		$pdo->query($sql);
	
		//return new \SoapFault('Server', $sql);
		return "OK";
	}
	
	/** @Soap\Method("getFournisseurs")
	 * @Soap\Param("count",phpType="int")
	 * @Soap\Param("offset",phpType="int")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("email",phpType="string")
	 * @Soap\Param("telephone_portable",phpType="string")
	 * @Soap\Param("reference_client",phpType="string")
	 * @Soap\Param("notes",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getFournisseursAction($count, $offset, $nom, $email, $telephone_portable, $reference_client, $notes)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GA001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($nom) || !is_string($email) || !is_string($telephone_portable)
			|| !is_string($reference_client) || !is_string($notes) || !is_int($offset) || !is_int($count)
		)// Vérif des arguments
			return new \SoapFault('Server', '[GA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();



		// Formation de la requete SQL
		$sql = 'SELECT id, nom, email, telephone_portable, reference_client, notes FROM fournisseur ';
		
		$sql .= 'WHERE ';
		$arguments = array();
		if (!empty($nom) || !empty($email) || !empty($telephone_portable) || !empty($reference_client) || !empty($notes)) {

			if (!empty($nom))
				array_push($arguments, array('nom' => $nom));
			if (!empty($email))
				array_push($arguments, array('email' => $email));
			if (!empty($telephone_portable))
				array_push($arguments, array('telephone_portable' => $telephone_portable));
			if (!empty($reference_client))
				array_push($arguments, array('reference_client' => $reference_client));
			if (!empty($notes))
				array_push($arguments, array('notes' => $notes));

			//$sql .= 'WHERE ';

			$i = 0;
			$taille_avant_fin = count($arguments) - 1;
			while ($i < $taille_avant_fin) {

				$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';

				$i++;
			}
			$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
			$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';



		}

		$sql .= ' est_visible=\'1\'';
		if ($offset != 0) {
			$sql .= ' ORDER BY nom ASC LIMIT ' . (int)$offset;
			if ($count != 0)
				$sql .= ',' . (int)$count;
		}
		else {
			$sql .= ' ORDER BY nom ASC';
		}

		//id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('id' => $row['id'], 'nom' => $row['nom'],
				'email' => $row['email'], 'telephone_portable' => $row['telephone_portable'],
				'reference_client'=>$row['reference_client'],'notes'=>$row['notes']);
			array_push($result, $ligne);
		}
		return json_encode($result);
		//return new \SoapFault('Server', $sql);
	}


	/**
	 * @Soap\Method("ajoutFournisseur")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("email",phpType="string")
	 * @Soap\Param("telephone_portable",phpType="string")
	 * @Soap\Param("reference_client",phpType="string")
	 * @Soap\Param("notes",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutFournisseurAction($nom, $email, $telephone_portable, $reference_client, $notes)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[AF001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom) || $nom == '') // Vérif des arguments
			return new \SoapFault('Server', '[AF002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		//$result = array();

		// Formation de la requete SQL
		$sql = 'SELECT id, nom, email, telephone_portable, reference_client FROM fournisseur WHERE
nom=' . $pdo->quote($nom) . ' AND email='.$pdo->quote($email).' AND telephone_portable='.$pdo->quote($telephone_portable).'
 AND reference_client='.$pdo->quote($reference_client).' AND est_visible=true ';
		//return new \SoapFault('Server', $sql);
		$resultat = $pdo->query($sql);
		if ($resultat->rowCount($sql) == 0) {

			//on insert le fournisseur
			$sql = 'INSERT INTO fournisseur(nom,email,telephone_portable,reference_client,notes)
VALUES(' . $pdo->quote($nom) . ',' . $pdo->quote($email) . ',
			' . $pdo->quote($telephone_portable) . ','.$pdo->quote($reference_client).','.$pdo->quote($notes).');';
			$pdo->query($sql);

			return "OK";
			//return new \SoapFault('Server', $sql);
		}

		return new \SoapFault('Server', '[AF003] Paramètres invalides.');

	}

	/**
	 * @Soap\Method("modifFournisseur")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("email",phpType="string")
	 * @Soap\Param("telephone_portable",phpType="string")
	 * @Soap\Param("reference_client",phpType="string")
	 * @Soap\Param("notes",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifFournisseurAction($id, $nom, $email, $telephone_portable, $reference_client, $notes)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MF001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom) || !is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[MF002] Paramètres invalides.');


		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service

		// Formation de la requete SQL
		$sql = 'UPDATE fournisseur SET nom=' . $pdo->quote($nom) . ',email=' . $pdo->quote($email) . '
		,telephone_portable=' . $pdo->quote($telephone_portable) . ',reference_client='.$pdo->quote($reference_client).'
		,notes='.$pdo->quote($notes).' WHERE id=' . $pdo->quote($id) . '';

		
		$pdo->query($sql);

		return "OK";

	}


	/**
	 * @Soap\Method("getAdresses")
	 * @Soap\Param("count",phpType="int")
	 * @Soap\Param("offset",phpType="int")
	 * @Soap\Param("est_fournisseur",phpType="boolean")
	 * @Soap\Param("ref_id",phpType="int")
	 * @Soap\Param("pays",phpType="string")
	 * @Soap\Param("ville",phpType="string")
	 * @Soap\Param("voie",phpType="string")
	 * @Soap\Param("num_voie",phpType="string")
	 * @Soap\Param("code_postal",phpType="string")
	 * @Soap\Param("num_appartement",phpType="string")
	 * @Soap\Param("telephone_fixe",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getAdressesAction($count, $offset, $est_fournisseur, $ref_id, $pays, $ville, $voie, $num_voie, $code_postal, $num_appartement, $telephone_fixe)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GA001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($pays) || !is_string($ville) || !is_string($voie) || !is_string($num_voie) || !is_string($code_postal)
			|| !is_string($num_appartement) || !is_string($telephone_fixe)
			|| !is_int($offset) || !is_int($count) || !is_bool($est_fournisseur)
			|| !is_int($ref_id)
		)// Vérif des arguments
			return new \SoapFault('Server', '[GA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		// Formation de la requete SQL
		$sql = 'SELECT adresse.id, type_adresse.nom as "type_adresse_nom", pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe FROM adresse 
				LEFT OUTER JOIN type_adresse ON type_adresse.id = adresse.ref_type_adresse ';

		$arguments = array();
		if (!empty($pays) || !empty($ville) || !empty($voie) || !empty($num_voie) || !empty($code_postal) || !empty($num_appartement)
			|| !empty($telephone_fixe) || !empty($ref_id)
		) {

			if (!empty($pays))
				array_push($arguments, array('pays' => $pays));
			if (!empty($ville))
				array_push($arguments, array('ville' => $ville));
			if (!empty($voie))
				array_push($arguments, array('voie' => $voie));
			if (!empty($num_voie))
				array_push($arguments, array('num_voie' => $num_voie));
			if (!empty($num_appartement))
				array_push($arguments, array('num_appartement' => $num_appartement));
			if (!empty($code_postal))
				array_push($arguments, array('code_postal' => $code_postal));
			if (!empty($telephone_fixe))
				array_push($arguments, array('telephone_fixe' => $telephone_fixe));
			if ($est_fournisseur==true && !empty($ref_id))
				array_push($arguments, array('ref_fournisseur' => $ref_id));
			if ($est_fournisseur==false && !empty($ref_id))
				array_push($arguments, array('ref_contact' => $ref_id));

			$sql .= 'WHERE ';

			$i = 0;
			$taille_avant_fin = count($arguments) - 1;
			while ($i < $taille_avant_fin) {
				if (key($arguments[$i]) == 'ref_fournisseur' || key($arguments[$i]) == 'ref_contact') {

					$sql .= ' ' . key($arguments[$i]) . '=' . $pdo->quote($arguments[$i][key($arguments[$i])]) . ' AND';
				} else {
					$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
					$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';
				}
				$i++;
			}
			if (key($arguments[$i]) == 'ref_fournisseur' || key($arguments[$i]) == 'ref_contact') {

				$sql .= ' ' . key($arguments[$i]) . '=' . $pdo->quote($arguments[$i][key($arguments[$i])]) . ' AND est_visible=\'1\'';
			} else {
				$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND est_visible=\'1\'';
			}


		}
		if ($offset != 0) {
			$sql .= ' ORDER BY ville ASC LIMIT ' . (int)$offset;
			if ($count != 0)
				$sql .= ',' . (int)$count;
		} else {
			$sql .= ' ORDER BY ville ASC';
		}

		//id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('id' => $row['id'], 'type_adresse_nom'=>$row['type_adresse_nom']
					, 'pays' => $row['pays'], 'ville' => $row['ville'], 'voie' => $row['voie'],
				'num_voie' => $row['num_voie'], 'code_postal' => $row['code_postal'], 'num_appartement' => $row['num_appartement'],
				'telephone_fixe' => $row['telephone_fixe']);
			array_push($result, $ligne);
		}
		return json_encode($result);
		//return new \SoapFault('Server', $sql);
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
	 * @Soap\Param("type_adresse",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutAdresseAction($est_fournisseur, $ref_id, $pays, $ville, $voie, $num_voie, $code_postal,
			 $num_appartement, $telephone_fixe, $type_adresse)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[AA001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($pays) || !is_string($ville) || !is_string($voie) || !is_string($num_voie) || !is_string($code_postal)
			|| !is_string($num_appartement) || !is_string($telephone_fixe)
			|| !is_bool($est_fournisseur) || (!is_string($ref_id) && !is_int($ref_id) || !is_string($type_adresse))
		) // Vérif des arguments
			return new \SoapFault('Server', '[AA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service

		// Formation de la requete SQL
		$tab_pays = json_decode($pays);
		$tab_ville = json_decode($ville);
		$tab_voie = json_decode($voie);
		$tab_num_voie = json_decode($num_voie);
		$tab_code_postal = json_decode($code_postal);
		$tab_num_appartement = json_decode($num_appartement);
		$tab_telephone_fixe = json_decode($telephone_fixe);
		$tab_type_adresse = json_decode($type_adresse);
		
		//on teste si il existe une adresse de facturation
		$flag_ad_fact = 'Non';
		if ($est_fournisseur){
		}
		
		else{
			$sql_test =' SELECT * FROM contact JOIN adresse ON adresse.ref_contact = contact.id 
					JOIN type_adresse ON type_adresse.id = adresse.ref_type_adresse 
					WHERE type_adresse.nom =\'Facturation\' AND contact.id = '.$pdo->quote($ref_id).' AND adresse.est_visible=true ';
			$result_test = $pdo->query($sql_test);
			if($result_test->rowCount()==0){
				$flag_ad_fact = 'Non';
			}
			else{
				$flag_ad_fact = 'Oui';
			}
			
			
		}

		$i = 0;
		foreach ($tab_pays as $pays) {

			$ville = $tab_ville[$i];
			$voie = $tab_voie[$i];
			$num_voie = $tab_num_voie[$i];
			$code_postal = $tab_code_postal[$i];
			$num_appartement = $tab_num_appartement[$i];
			$telephone_fixe = $tab_telephone_fixe[$i];
			$type_adresse = $tab_type_adresse[$i];


			$sql = 'SELECT id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe FROM adresse
WHERE pays=' . $pdo->quote($pays) . ' AND ville=' . $pdo->quote($ville) . ' AND voie=' . $pdo->quote($voie) . '
AND num_voie=' . $pdo->quote($num_voie) . ' AND adresse.est_visible=true ';

			if ($est_fournisseur){
				$sql .= 'AND ref_fournisseur=' . $pdo->quote($ref_id) . '';
			}
				
			else{
				//on teste si le contact a deja une adresse de facturation
				$sql .= 'AND ref_contact=' . $pdo->quote($ref_id) . '';
			}

			//on teste si l'adresse existe déjà
			$resultat = $pdo->query($sql);

			$id_typad = 1;
			
			if ($resultat->rowCount() == 0) {
				//insertion des données
				if ($est_fournisseur) {
					$sql = 'INSERT INTO adresse(ref_fournisseur,pays,ville,voie,num_voie,code_postal,num_appartement,telephone_fixe) VALUES(
' . $pdo->quote($ref_id) . ',' . $pdo->quote($pays) . ',' . $pdo->quote($ville) . ',' . $pdo->quote($voie) . ',' . $pdo->quote($num_voie) . ',
' . $pdo->quote($code_postal) . ',' . $pdo->quote($num_appartement) . ',' . $pdo->quote($telephone_fixe) . ')';
				} 
				elseif (!$est_fournisseur && $flag_ad_fact=='Non') {
					$sql_typad = 'SELECT MAX(id) as "max_id_typad",(SELECT MAX(id) FROM type_adresse)
							 as "max_id" FROM type_adresse WHERE nom = '.$pdo->quote($type_adresse).'';
					$result_typad = $pdo->query($sql_typad);
					
					foreach($result_typad as $row){
						if(empty($row['max_id_typad'])){
							//on insert le type adresse
							$sql='INSERT INTO type_adresse(nom) VALUES('.$pdo->quote($type_adresse).');';
							$pdo->query($sql);
							//on save la ref
							$id_typad = $row['max_id'] + 1;
						}
						else 
							$id_typad = $row['max_id_typad'];
					}
							
					$sql = 'INSERT INTO adresse(ref_contact,pays,ville,voie,num_voie,code_postal,num_appartement,
							telephone_fixe,ref_type_adresse) VALUES(
' . $pdo->quote($ref_id) . ',' . $pdo->quote($pays) . ',' . $pdo->quote($ville) . ',' . $pdo->quote($voie) . ',' . $pdo->quote($num_voie) . ',
' . $pdo->quote($code_postal) . ',' . $pdo->quote($num_appartement) . ',' . $pdo->quote($telephone_fixe) . ',
		'.$pdo->quote($id_typad).')';
				}
				else{
					$sql_typad = 'SELECT MAX(id) as "max_id_typad",(SELECT MAX(id) FROM type_adresse)
							 as "max_id" FROM type_adresse WHERE nom = \'Autre\'';
					$result_typad = $pdo->query($sql_typad);
						
					foreach($result_typad as $row){
						if(empty($row['max_id_typad'])){
							//on insert le type adresse
							$sql='INSERT INTO type_adresse(nom) VALUES(\'Autre\');';
							$pdo->query($sql);
							//on save la ref
							$id_typad = $row['max_id'] + 1;
						}
						else
							$id_typad = $row['max_id_typad'];
					}
					
					$sql = 'INSERT INTO adresse(ref_contact,pays,ville,voie,num_voie,code_postal,num_appartement,
							telephone_fixe, ref_type_adresse) VALUES(
' . $pdo->quote($ref_id) . ',' . $pdo->quote($pays) . ',' . $pdo->quote($ville) . ',' . $pdo->quote($voie) . ',' . $pdo->quote($num_voie) . ',
' . $pdo->quote($code_postal) . ',' . $pdo->quote($num_appartement) . ',' . $pdo->quote($telephone_fixe) . ',
		'.$pdo->quote($id_typad).')';
				}
				$pdo->query($sql);

				//return new \SoapFault('Server','[AA00011] '.$sql.'.');
				


			} else {
				return new \SoapFault('Server', '[AA003] Paramètres invalides.');
			}

			$i++;
		}
		return "OK";


	}

	/**
	 * Permet d'avoir les statistiques des ventes par mois.
	 *
	 * @Soap\Method("statsVenteTopVente")
	 * @Soap\Param("nbMois",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function statsVenteTopVenteAction($nbMois)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SVTVM001] Vous n\'avez pas les droits nécessaires.');

		if (!is_int($nbMois)) // Vérif des arguments
			return new \SoapFault('Server', '[SVTV002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		$sql = 'SELECT nom_produit, SUM(marge) as "marge" FROM(
SELECT nom_produit,article_id, SUM(marge) as "marge" FROM (
SELECT id_facture ,nom_produit , date_de_facture, UPPER(nom_contact) as nom_contact_maj, prenom_contact, nom_article ,article_id , nb_article,montant_client, montant_fournisseur, reduction_article,
						SUM(CASE
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) AS montant,(SUM(CASE
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) + (montant_fournisseur * nb_article)) as "marge"
				        FROM(
				        SELECT 
							   lf.id as "ligne_facture_id",
				               a.id as "article_id",
							   a.code_barre as "nom_article",
				               px.montant_client as "prix_id",
				               f.id as "id_facture" ,
				               f.date_facture as "date_de_facture",
				               c.nom as "nom_contact",
							   c.prenom as "prenom_contact",
							   c.id as "id_contact",
							   ad.num_voie as "adresse_numero",
							   ad.voie as "adresse_rue",
							   ad.code_postal as "adresse_code_postal",
							   ad.ville as "adresse_ville",
							   ad.pays as "adresse_pays",
				               r.reduction as "reduction_article",
				               m.quantite_mouvement as "nb_article",
				               r.type_reduction as "type_reduction",
				               px.montant_client as "montant_client",
                               px.montant_fournisseur as "montant_fournisseur",
							   pt.nom as "nom_produit",
							   mp.nom as "nom_moyen_paiement"
				      
				        FROM facture f
						JOIN ligne_facture lf ON lf.ref_facture = f.id 
						JOIN mouvement_stock m ON lf.ref_mvt_stock = m.id
						JOIN article a ON m.ref_article = a.id
				        JOIN prix px ON px.ref_article = a.id AND px.id = 
                        (SELECT MAX(prix.id) FROM prix WHERE prix.date_modif<f.date_facture AND prix.ref_article=a.id)
				        JOIN produit pt ON a.ref_produit = pt.id
				        LEFT OUTER JOIN remise r ON lf.ref_remise = r.id
				        LEFT OUTER JOIN contact c ON f.ref_contact = c.id
						LEFT OUTER JOIN adresse ad ON c.id = ad.ref_contact AND ad.ref_type_adresse = 1
                        LEFT OUTER JOIN moyen_paiement mp ON f.ref_moyen_paiement = mp.id';
				
	  	if($nbMois == 3){
			$sql = $sql . ' WHERE f.date_facture BETWEEN (curdate() - INTERVAL 3 MONTH) AND curdate() ';
	  	}
	  	else if($nbMois == 6){
	  		$sql = $sql . ' WHERE f.date_facture BETWEEN (curdate() - INTERVAL 6 MONTH) AND curdate() ';
	  	}
	  	else{
	  		$sql = $sql . ' WHERE month(f.date_facture) = month(curdate())';
	  	}
				
		$sql = $sql . ' ) t GROUP BY ligne_facture_id ORDER BY marge DESC
						 ) ta GROUP BY nom_produit, article_id
						 ) tab GROUP BY nom_produit LIMIT 3' ;

		$resultat = $pdo->query($sql);
		foreach ($resultat as $row) {
			$jour = array('produit' => $row['nom_produit'], 'montant' => $row['marge']);
			array_push($result, $jour);
		}

		return json_encode($result);
	}

	/**
	 * Permet d'avoir les statistiques des ventes par mois.
	 *
	 * @Soap\Method("statsVenteMoyenneParMois")
	 * @Soap\Param("mois",phpType="string")
	 * @Soap\Param("annee",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function statsVenteMoyenneParMoisAction($mois,$annee)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SVMVPM001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($mois) || !is_int($annee)) // Vérif des arguments
			return new \SoapFault('Server', '[SVMVPM002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result_n = array();
		$result_n_moins_un = array();
		$result = array();

		$sql_default = 'SELECT SUM(montant) as "montant_du_jour",date_du_jour as "date_bon_format" FROM (		
					SELECT date_de_facture,SUM(CASE
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) AS "montant",date_de_facture as "date_du_jour"
                        FROM( 
				        SELECT 
							   lf.id as "ligne_facture_id",
							   f.id as "id_facture",
				               f.date_facture as "date_de_facture",
				               r.reduction as "reduction_article",
				               m.quantite_mouvement as "nb_article",
				               r.type_reduction as "type_reduction",
				               px.montant_client as "montant_client"
				      
				        FROM facture f
						JOIN ligne_facture lf ON lf.ref_facture = f.id
						JOIN mouvement_stock m ON lf.ref_mvt_stock = m.id
						JOIN article a ON m.ref_article = a.id
				        JOIN prix px ON px.ref_article = a.id AND px.id =
                        (SELECT MAX(prix.id) FROM prix WHERE prix.date_modif<f.date_facture AND prix.ref_article=a.id)
				        JOIN produit pt ON a.ref_produit = pt.id
				        LEFT OUTER JOIN remise r ON lf.ref_remise = r.id ';
						
		if($mois != '' && $annee != ''){
			$sql_default = $sql_default . 'WHERE month(f.date_facture) = '.$pdo->quote($mois).'
						AND year(f.date_facture) = '.$pdo->quote($annee).'
						 ) t GROUP BY ligne_facture_id ORDER BY id_facture ASC
				)f GROUP BY DAY(date_du_jour)';
		}
		else{
			$sql_default = $sql_default . 'WHERE month(f.date_facture) = month(curdate())
						AND year(f.date_facture) = year(curdate())
						 ) t GROUP BY ligne_facture_id ORDER BY id_facture ASC
				)f GROUP BY DAY(date_du_jour)';
		}
		
		$resultat_par_defaut_n = $pdo->query($sql_default);
		
		//On complete les jours manquant entre les dates de factures.
		$jour_precedent = 1;
		foreach ($resultat_par_defaut_n as $row) {
			$jour_courant = date('d', strtotime($row['date_bon_format']));
			if(($jour_courant - $jour_precedent) > 1){
				$i = $jour_precedent;
				if($i == 1){
						$mois_n = array('montant_n' => 0, 'jour_n' => $i);
						array_push($result_n, $mois_n);
				}
		
				while ($i < $jour_courant-1){
					$mois_n = array('montant_n' => 0, 'jour_n' => $i+1);
					array_push($result_n, $mois_n);
					$i++;
				}
			}
			$mois_n = array('montant_n' => $row['montant_du_jour'], 'jour_n' => $jour_courant);
			array_push($result_n, $mois_n);
			$jour_precedent = $jour_courant;
		}
		
		//requete pour le mois demander ou courant mais de l'ann�e n-1
		$sql_default_n_moins_un = 'SELECT SUM(montant) as "montant_du_jour",date_du_jour as "date_bon_format" FROM (
					SELECT date_de_facture,SUM(CASE
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) AS "montant",date_de_facture as "date_du_jour"
                        FROM(
				        SELECT
							   lf.id as "ligne_facture_id",
							   f.id as "id_facture",
				               f.date_facture as "date_de_facture",
				               r.reduction as "reduction_article",
				               m.quantite_mouvement as "nb_article",
				               r.type_reduction as "type_reduction",
				               px.montant_client as "montant_client"
						
				        FROM facture f
						JOIN ligne_facture lf ON lf.ref_facture = f.id
						JOIN mouvement_stock m ON lf.ref_mvt_stock = m.id
						JOIN article a ON m.ref_article = a.id
				        JOIN prix px ON px.ref_article = a.id AND px.id =
                        (SELECT MAX(prix.id) FROM prix WHERE prix.date_modif<f.date_facture AND prix.ref_article=a.id)
				        JOIN produit pt ON a.ref_produit = pt.id
				        LEFT OUTER JOIN remise r ON lf.ref_remise = r.id';
		
		if($mois != '' && $annee != ''){
			$sql_default_n_moins_un = $sql_default_n_moins_un.' WHERE month(f.date_facture) = '.$pdo->quote($mois).'
						AND year(f.date_facture) = '.$pdo->quote($annee-1).'
						 ) t GROUP BY ligne_facture_id ORDER BY id_facture ASC
				)f GROUP BY DAY(date_du_jour)';
		}
		else{
			$sql_default_n_moins_un = $sql_default_n_moins_un . ' WHERE month(f.date_facture) = month(curdate())
						AND year(f.date_facture) = year(curdate() - INTERVAL 1 YEAR)
						 ) t GROUP BY ligne_facture_id ORDER BY id_facture ASC
				)f GROUP BY DAY(date_du_jour)';
		}
				
		$resultat_n_moins_un = $pdo->query($sql_default_n_moins_un);
		
		//On complete les jours manquant entre les dates de factures.
		$jour_precedent = 0;
		$i = $jour_precedent;
		foreach ($resultat_n_moins_un as $row) {
			$jour_courant = date('d', strtotime($row['date_bon_format']));
				if(($jour_courant - $jour_precedent) > 1){
					$i = $jour_precedent;
					if($i == 1){
						$mois_n = array('montant_n_moins_un' => 0, 'jour_n_moins_un' => $i);
						array_push($result_n_moins_un, $mois_n);
					}
					
					while ($i < $jour_courant-1){
						$mois_n = array('montant_n_moins_un' => 0, 'jour_n_moins_un' => $i+1);
						array_push($result_n_moins_un, $mois_n);
						$i++;
					}
				}
			$mois_n = array('montant_n_moins_un' => $row['montant_du_jour'], 'jour_n_moins_un' => $jour_courant);
			array_push($result_n_moins_un, $mois_n);
			$jour_precedent = $jour_courant;
		}
		
		//On rajoute les jours manquant du mois apres la date de facture pour avoir le mois complet.
		// Ce mois qui sert de reference pour la courbe des stats donc mois en entier obligatoire.
		$nombre_de_jour_du_mois = date('t',time(0, 0, 0, $mois, 1, $annee-1));
		$jour_c = $jour_precedent;
		while ($jour_c < $nombre_de_jour_du_mois){
			$jour_c++;
			$mois_n = array('montant_n_moins_un' => 0, 'jour_n_moins_un' => $jour_c);
			array_push($result_n_moins_un, $mois_n);
		}
		
		
		array_push($result, $result_n);
		array_push($result, $result_n_moins_un);
		return json_encode($result);
	}

	/**
	 * @Soap\Method("statsVenteMoyenneParAnnee")
	 * @Soap\Param("annee",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function statsVenteMoyenneParAnneeAction($annee)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SVMVPA001] Vous n\'avez pas les droits nécessaires.');
	
		if (!is_int($annee)) // Vérif des arguments
			return new \SoapFault('Server', '[SVMVPA002] Paramètres invalides.');
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result_n = array();
		$result_n_moins_un = array();
		$result = array();
	
		$sql_default = 'SELECT SUM(montant) as "montant_du_mois",date_du_jour as "date_bon_format" FROM (
					SELECT date_de_facture,SUM(CASE
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) AS "montant",date_de_facture as "date_du_jour"
                        FROM(
				        SELECT
							   lf.id as "ligne_facture_id",
							   f.id as "id_facture",
				               f.date_facture as "date_de_facture",
				               r.reduction as "reduction_article",
				               m.quantite_mouvement as "nb_article",
				               r.type_reduction as "type_reduction",
				               px.montant_client as "montant_client"
	
				        FROM facture f
						JOIN ligne_facture lf ON lf.ref_facture = f.id
						JOIN mouvement_stock m ON lf.ref_mvt_stock = m.id
						JOIN article a ON m.ref_article = a.id
				        JOIN prix px ON px.ref_article = a.id AND px.id =
                        (SELECT MAX(prix.id) FROM prix WHERE prix.date_modif<f.date_facture AND prix.ref_article=a.id)
				        JOIN produit pt ON a.ref_produit = pt.id
				        LEFT OUTER JOIN remise r ON lf.ref_remise = r.id ';
	
		if($annee != ''){
			$sql_default = $sql_default . 'WHERE year(f.date_facture) = '.$pdo->quote($annee).'
						 ) t GROUP BY ligne_facture_id ORDER BY id_facture ASC
				)f GROUP BY MONTH(date_du_jour)';
		}
		else{
			$sql_default = $sql_default . 'WHERE year(f.date_facture) = year(curdate())
						 ) t GROUP BY ligne_facture_id ORDER BY id_facture ASC
				)f GROUP BY MONTH(date_du_jour)';
		}
	
		$resultat_par_defaut_n = $pdo->query($sql_default);
	
		//On complete les jours manquant entre les dates de factures.
		$mois_precedent = 1;
		foreach ($resultat_par_defaut_n as $row) {
			$mois_courant = date('m', strtotime($row['date_bon_format']));
			if(($mois_courant - $mois_precedent) > 1){
				$i = $mois_precedent;
				if($i == 1){
					$mois_n = array('montant_n' => 0, 'jour_n' => $i);
					array_push($result_n, $mois_n);
				}
	
				while ($i < $mois_courant-1){
					$mois_n = array('montant_n' => 0, 'jour_n' => $i+1);
					array_push($result_n, $mois_n);
					$i++;
				}
			}
			$mois_n = array('montant_n' => $row['montant_du_mois'], 'jour_n' => $mois_courant);
			array_push($result_n, $mois_n);
			$mois_precedent = $mois_courant;
		}
	
		//requete pour le mois demander ou courant mais de l'ann�e n-1
		$sql_default_n_moins_un = 'SELECT SUM(montant) as "montant_du_jour",date_du_jour as "date_bon_format" FROM (
					SELECT date_de_facture,SUM(CASE
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) AS "montant",date_de_facture as "date_du_jour"
                        FROM(
				        SELECT
							   lf.id as "ligne_facture_id",
							   f.id as "id_facture",
				               f.date_facture as "date_de_facture",
				               r.reduction as "reduction_article",
				               m.quantite_mouvement as "nb_article",
				               r.type_reduction as "type_reduction",
				               px.montant_client as "montant_client"
	
				        FROM facture f
						JOIN ligne_facture lf ON lf.ref_facture = f.id
						JOIN mouvement_stock m ON lf.ref_mvt_stock = m.id
						JOIN article a ON m.ref_article = a.id
				        JOIN prix px ON px.ref_article = a.id AND px.id =
                        (SELECT MAX(prix.id) FROM prix WHERE prix.date_modif<f.date_facture AND prix.ref_article=a.id)
				        JOIN produit pt ON a.ref_produit = pt.id
				        LEFT OUTER JOIN remise r ON lf.ref_remise = r.id';
	
		if($annee != ''){
			$annee = $annee - 1;
			$sql_default_n_moins_un = $sql_default_n_moins_un.' WHERE year(f.date_facture) = '.$pdo->quote($annee).'
						 ) t GROUP BY ligne_facture_id ORDER BY id_facture ASC
				)f GROUP BY MONTH(date_du_jour)';
		}
		else{
			$sql_default_n_moins_un = $sql_default_n_moins_un . ' WHERE year(f.date_facture) = year(curdate() - INTERVAL 1 YEAR)
						 ) t GROUP BY ligne_facture_id ORDER BY id_facture ASC
				)f GROUP BY MONTH(date_du_jour)';
		}
	
		$resultat_n_moins_un = $pdo->query($sql_default_n_moins_un);
	
		//On complete les mois manquant entre les dates de factures.
		$mois_precedent = 1;
		$dernier_mois = 0;
		foreach ($resultat_n_moins_un as $row) {
			$dernier_mois = date('m', strtotime($row['date_bon_format']));
			if(($dernier_mois - $mois_precedent) > 1){
				$i = $mois_precedent;
				if($i == 1){
					$mois_n = array('montant_n_moins_un' => 0, 'jour_n_moins_un' => $i);
					array_push($result_n_moins_un, $mois_n);
				}
	
				while ($i < $dernier_mois-1){
					$mois_n = array('montant_n_moins_un' => 0, 'jour_n_moins_un' => $i+1);
					array_push($result_n_moins_un, $mois_n);
					$i++;
				}
			}
			$mois_n = array('montant_n_moins_un' => $row['montant_du_jour'], 'jour_n_moins_un' => $dernier_mois);
			array_push($result_n_moins_un, $mois_n);
			$mois_precedent = $dernier_mois;
		}
	
		//On rajoute les jours manquant du mois apres la date de facture pour avoir le mois complet.
		// Ce mois qui sert de reference pour la courbe des stats donc mois en entier obligatoire.
		$nombre_de_mois_dans_annee = 12;
		$mois_c = $dernier_mois;
		while ($mois_c < $nombre_de_mois_dans_annee){
			$mois_c++;
			$mois_n = array('montant_n_moins_un' => 0, 'jour_n_moins_un' => $mois_c);
			array_push($result_n_moins_un, $mois_n);
		}
	
		array_push($result, $result_n);
		array_push($result, $result_n_moins_un);
		return json_encode($result);
	}
	
	/**
	 * @Soap\Method("modifAdresse")
	 * @Soap\Param("est_visible",phpType="string")
	 * @Soap\Param("id_ad",phpType="string")
	 * @Soap\Param("pays",phpType="string")
	 * @Soap\Param("ville",phpType="string")
	 * @Soap\Param("voie",phpType="string")
	 * @Soap\Param("num_voie",phpType="string")
	 * @Soap\Param("code_postal",phpType="string")
	 * @Soap\Param("num_appartement",phpType="string")
	 * @Soap\Param("telephone_fixe",phpType="string")
	 * @Soap\Param("type_adresse",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifAdresseAction($est_visible, $id_ad, $pays, $ville, $voie, $num_voie,
			 $code_postal, $num_appartement, $telephone_fixe,$type_adresse)
	{

		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MA001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($est_visible) || !is_string($pays) || !is_string($ville) || !is_string($voie) || !is_string($num_voie) || !is_string($code_postal)
			|| !is_string($num_appartement) || !is_string($telephone_fixe) || !is_string($id_ad) || !is_string($type_adresse)
		) // Vérif des arguments
			return new \SoapFault('Server', '[MA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service

		//Formation de la requête SQL
		//on recupere les parametres de la requête sous forme de tableau
		$tab_est_visible = json_decode($est_visible);
		$tab_id = json_decode($id_ad);
		$tab_pays = json_decode($pays);
		$tab_ville = json_decode($ville);
		$tab_voie = json_decode($voie);
		$tab_num_voie = json_decode($num_voie);
		$tab_code_postal = json_decode($code_postal);
		$tab_num_appartement = json_decode($num_appartement);
		$tab_telephone_fixe = json_decode($telephone_fixe);
		$tab_type_adresse = json_decode($type_adresse);

		//variable d'incrementation
		$i = 0;
		foreach ($tab_id as $id_ad) {
			$est_visible = $tab_est_visible[$i];
			$pays = $tab_pays[$i];
			$ville = $tab_ville[$i];
			$voie = $tab_voie[$i];
			$num_voie = $tab_num_voie[$i];
			$code_postal = $tab_code_postal[$i];
			$num_appartement = $tab_num_appartement[$i];
			$telephone_fixe = $tab_telephone_fixe[$i];
			$type_adresse = $tab_type_adresse[$i];
			
			//on recupere l'id des types d'adresse pour voir si ils ont bien ete inseres
			$sql_test = 'SELECT MAX(id) as "max_id_ad",
					(SELECT MAX(id) FROM type_adresse WHERE nom=\'Autre\') as "max_id_autre",
					(SELECT MAX(id) FROM type_adresse) as "max_id"
							 FROM type_adresse WHERE nom=\'Facturation\';';
			
			$result_test = $pdo->query($sql_test);
			
			
			
			foreach($result_test as $row){
				$id_typad= $row['max_id_ad'];
				$id_autre= $row['max_id_autre'];

			}
			//si le type d'adresse est facturation alors on procede a la verif qu'il y est uniquement
			//une et une seule adresse de facturation par contact
			if($type_adresse=='Facturation'){
				
				
				$sql_u = 'select MAX(contact.id) as "max_id_contact" from contact join adresse on adresse.ref_contact = contact.id 
						where adresse.id = '.$pdo->quote($id_ad).';';
				foreach($pdo->query($sql_u) as $ligne){
					$id_contact = $ligne['max_id_contact'];
				}
				
				if(!empty($id_contact)){
					//on recupere l'id de la premiere adresse de facturation d'un contact
					//si cette adresse est la premiere on la conserve sinon on la modifie
					$sql_fact = 'select min(adresse.id) as "min_ad_fact" from adresse 
join type_adresse on adresse.ref_type_adresse = type_adresse.id
where type_adresse.nom=\'Facturation\' and ref_contact = '.$pdo->quote($id_contact).' and adresse.est_visible=true;';
					
					$ad_fact = 0;
					foreach($pdo->query($sql_fact) as $row){
						$ad_fact= $row['min_ad_fact'];
					
					}
					
					//l'adresse n'est pas la premiere du contact on la met a jour
					if($id_ad != $ad_fact){
						$sql_u ='UPDATE adresse SET ref_type_adresse='.$pdo->quote($id_autre).'
						WHERE ref_type_adresse='.$pdo->quote($id_typad).' AND ref_contact='.$pdo->quote($id_contact).'';
						$pdo->query($sql_u);
					}
					//maj globale
					$sql = 'UPDATE adresse SET est_visible=' . $pdo->quote($est_visible) . ',pays=' . $pdo->quote($pays) . ', ville=' . $pdo->quote($ville) . ', voie=' . $pdo->quote($voie) . ',
		num_voie=' . $pdo->quote($num_voie) . ',code_postal=' . $pdo->quote($code_postal) . ',num_appartement=' . $pdo->quote($num_appartement) . ',
		telephone_fixe=' . $pdo->quote($telephone_fixe) . ', ref_type_adresse='.$pdo->quote($id_typad).'
				WHERE id=' . $pdo->quote($id_ad) . '';
					
					
				}
				
				
			}
			//autrement il s'agit d'une adresse fournisseur don pas de probleme de facturation
			else{
				$sql = 'UPDATE adresse SET est_visible=' . $pdo->quote($est_visible) . ',pays=' . $pdo->quote($pays) . ', ville=' . $pdo->quote($ville) . ', voie=' . $pdo->quote($voie) . ',
		num_voie=' . $pdo->quote($num_voie) . ',code_postal=' . $pdo->quote($code_postal) . ',num_appartement=' . $pdo->quote($num_appartement) . ',
		telephone_fixe=' . $pdo->quote($telephone_fixe) . ', ref_type_adresse='.$pdo->quote($id_autre).' WHERE id=' . $pdo->quote($id_ad) . '';
			}
			
			//return new \SoapFault('Server', $sql);
			$pdo->query($sql);

			$i++;
		}

		return "OK";

	}

	/**
	 * @Soap\Method("ajoutContact")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("prenom",phpType="string")
	 * @Soap\Param("date_naissance",phpType="string")
	 * @Soap\Param("civilite",phpType="string")
	 * @Soap\Param("email",phpType="string")
	 * @Soap\Param("telephone_portable",phpType="string")
	 * @Soap\Param("ok_sms",phpType="boolean")
	 * @Soap\Param("ok_mail",phpType="boolean")
	 * @Soap\Param("notes",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutContactAction($nom, $prenom, $date_naissance, $civilite, $email, $telephone_portable, $ok_sms, $ok_mail, $notes)
	{
		
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[AC001] Vous n\'avez pas les droits nécessaires.');

		if ((!is_string($nom) || !is_string($prenom) || !is_string($date_naissance) || !is_string($civilite))
				 || $nom == '') // Vérif des arguments
			return new \SoapFault('Server', '[AC002] Paramètres invalides.');
		
		//return new \SoapFault('Server',$date_naissance);

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		//$result = array();

		//test de la date de naissance
		$split = array();
		if (preg_match ("/([0-9]{2})\/([0-9]{2})\/([0-9]{4})/", $date_naissance, $split))
		{
			if(checkdate($split[2],$split[1],$split[3]))
			{
				$date_naissance = $split[3].'-'.$split[2].'-'.$split[1];
				//return new \SoapFault('Server','ici');
			}
			else
			{
				$date_naissance='0000-00-00';
			}
		}
		else
		{
			$date_naissance='0000-00-00';
		}
		
		//return new \SoapFault('Server',$date_naissance);

		// Formation de la requete SQL
		$sql = 'SELECT id, nom, prenom, date_naissance, civilite, email, telephone_portable FROM contact WHERE nom=' . $pdo->quote($nom) . '
		 AND prenom=' . $pdo->quote($prenom) . ' AND date_naissance=' . $pdo->quote($date_naissance) . ' AND civilite=' . $pdo->quote($civilite) . '
		 AND email=' . $pdo->quote($email) . ' AND telephone_portable=' . $pdo->quote($telephone_portable) . ' AND est_visible=true ';

		
		//on controle que les variables ok sms et mail par rapport a la checkbox ce qui renvoie on en cas de vrai
		//on ne peut pas passer 1 et 0 car 0 est vide
		if ($ok_sms == 'on') {
			$int_ok_sms = 1;
		} else {
			$int_ok_sms = 0;
		}

		if ($ok_mail == 'on') {
			$int_ok_mail = 1;
		} else {
			$int_ok_mail = 0;
		}
		//return new \SoapFault('Server',$sql);
		$resultat = $pdo->query($sql);
		//$resultat = '';
		if ($resultat->rowCount($sql) == 0) {
			//if($resultat == '') {


			//on insert le fournisseur

			$sql = 'INSERT INTO contact(nom,prenom,date_naissance,civilite,email,telephone_portable,ok_sms,ok_mail,notes)
VALUES(' . $pdo->quote($nom) . ',' . $pdo->quote($prenom) . ',' . $pdo->quote($date_naissance) . ',' . $pdo->quote($civilite) . ',' . $pdo->quote($email) . ',
			' . $pdo->quote($telephone_portable) . ',' . $pdo->quote($int_ok_sms) . ',' . $pdo->quote($int_ok_mail) . ','.$pdo->quote($notes).');';
			$pdo->query($sql);

			return "OK";
			//return new \SoapFault('Server',$sql);
		}

		return new \SoapFault('Server', '[AC003] Ce contact existe déjà');
		

	}

	/**
	 * @Soap\Method("getContacts")
	 * @Soap\Param("count",phpType="int")
	 * @Soap\Param("offset",phpType="int")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("prenom",phpType="string")
	 * @Soap\Param("date_naissance",phpType="string")
	 * @Soap\Param("civilite",phpType="string")
	 * @Soap\Param("email",phpType="string")
	 * @Soap\Param("telephone_portable",phpType="string")
	 * @Soap\Param("ok_sms",phpType="string")
	 * @Soap\Param("ok_mail",phpType="string")
	 * @Soap\Param("notes",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getContactsAction($count, $offset, $nom, $prenom, $date_naissance, $civilite, $email, $telephone_portable, $ok_sms, $ok_mail, $notes)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GC001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($nom) || !is_string($prenom) || !is_string($date_naissance)
			|| !is_string($civilite) || !is_string($email) || !is_string($telephone_portable)
			|| !is_string($ok_sms) || !is_string($ok_mail) || !is_string($notes)
			|| !is_int($offset) || !is_int($count)
		)// Vérif des arguments
			return new \SoapFault('Server', '[GC002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();



		// Formation de la requete SQL
		$sql = 'SELECT id, nom, prenom, DATE_FORMAT(date_naissance, "%d/%m/%Y") AS date_naissance, civilite, email, telephone_portable, ok_sms, ok_mail, notes 
				FROM contact ';
		
		
		/*
		 * arguments permet de rassembler sous la forme de clef valeurs tous les parametres renseignés du where
		 * ainsi on peut utiliser cette clé dans la requête sql et la valeur comme valeur du where
		 * exemple clef = code_barre , valeur = 1234 on a WHERE code_barre = '1234'
		 */
		$arguments = array();
		
		$sql .= 'WHERE ';
		
		if (!empty($nom) || !empty($prenom) || !empty($date_naissance) || !empty($civilite) || !empty($email) || !empty($telephone_portable) ||
			!empty($ok_sms)
			|| !empty($ok_mail) || !empty($notes)
		) {

			//return new \SoapFault('Server', $ok_mail);
			//pour gerer les ok_sms avec des checkbox j'ai du traduire en chaine de caractere pour ensuite
			//assigner une valeur bool car false, 0 et '' sont considérés comme vides
			if (!empty($ok_sms) && ($ok_sms == 'on' || $ok_sms=='1' || $ok_sms==1 || $ok_sms=='Oui')) {
				array_push($arguments,array('ok_sms'=>1));
			} elseif(!empty($ok_sms) && ($ok_sms == 'off' || $ok_sms=='Non' || $ok_sms=='0')) {
				array_push($arguments,array('ok_sms'=>0));
			}

			if (!empty($ok_mail) && ($ok_mail == 'on' || $ok_mail=='1'|| $ok_mail==1 || $ok_mail=='Oui')) {
				array_push($arguments,array('ok_mail'=>1));
			} elseif(!empty($ok_mail) && ($ok_mail == 'off' || $ok_mail=='Non' || $ok_mail=='0')) {
				array_push($arguments,array('ok_mail'=>0));
			}

			//return new \SoapFault('Server', $int_ok_mail);

			if (!empty($nom))
				array_push($arguments, array('nom' => $nom));
			if (!empty($prenom))
				array_push($arguments, array('prenom' => $prenom));
			if (!empty($date_naissance)){
				$split = array();
				if (preg_match ("/([0-9]{2})\/([0-9]{2})\/([0-9]{4})/", $date_naissance, $split))
				{
					if(checkdate($split[2],$split[1],$split[3]))
					{
						$date_naissance = $split[3].'-'.$split[2].'-'.$split[1];
						//return new \SoapFault('Server','ici');
					}
					else
					{
						$date_naissance='0000-00-00';
					}
				}
				else
				{
					$date_naissance='0000-00-00';
				}
				array_push($arguments, array('date_naissance' => $date_naissance));
			}
			if (!empty($civilite))
				array_push($arguments, array('civilite' => $civilite));
			if (!empty($email))
				array_push($arguments, array('email' => $email));
			if (!empty($telephone_portable))
				array_push($arguments, array('telephone_portable' => $telephone_portable));
			if (!empty($notes))
				array_push($arguments, array('notes' => $notes));

			//$sql .= 'WHERE ';

			$i = 0;
			$taille_avant_fin = count($arguments) - 1;
			while ($i < $taille_avant_fin) {

				if(key($arguments[$i])=='civilite')
					$val = $arguments[$i][key($arguments[$i])];
				else
					$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';

				$i++;
			}
			if(key($arguments[$i])=='civilite')
					$val = $arguments[$i][key($arguments[$i])];
				else
					$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
			//$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND est_visible=\'1\'';
				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';



		}

		//new
		$sql .= ' est_visible=\'1\'';
		if ($offset != 0) {
			$sql .= ' ORDER BY nom ASC LIMIT ' . (int)$offset;
			if ($count != 0)
				$sql .= ',' . (int)$count;
		} else {
			$sql .= ' ORDER BY nom ASC';
		}

		//id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('id' => $row['id'], 'nom' => $row['nom'], 'prenom' => $row['prenom'],'date_naissance'=>
			$row['date_naissance'], 'civilite' => $row['civilite'],
				'email' => $row['email'], 'telephone_portable' => $row['telephone_portable'], 'ok_sms' => $row['ok_sms'],
				'ok_mail' => $row['ok_mail'],'notes'=>$row['notes']);
			array_push($result, $ligne);
		}
		return json_encode($result);
		//return new \SoapFault('Server', $sql);
	}

	/**
	 * @Soap\Method("supprContact")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function supprContactAction($id){
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SC001] Vous n\'avez pas les droits nécessaires.');
		
		
		
		if (!is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[SCC002] Paramètres invalides.');
		
		
		
		$sql = 'UPDATE contact SET est_visible=0 WHERE id='.$pdo->quote($id).';';
		
		//return new \SoapFault('Server', 'coucou');
		
		$pdo->query($sql);
		
		//return new \SoapFault('Server', $sql);
		return "OK";
	}
	
	/**
	 * @Soap\Method("modifContact")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Param("nom",phpType="string")
	 * @Soap\Param("prenom",phpType="string")
	 * @Soap\Param("date_naissance",phpType="string")
	 * @Soap\Param("civilite",phpType="string")
	 * @Soap\Param("email",phpType="string")
	 * @Soap\Param("telephone_portable",phpType="string")
	 * @Soap\Param("ok_sms",phpType="string")
	 * @Soap\Param("ok_mail",phpType="string")
	 * @Soap\Param("notes",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifContactAction($id, $nom, $prenom, $date_naissance, $civilite, $email, $telephone_portable, $ok_sms, $ok_mail,$notes)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MC001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom) || !is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[MC002] Paramètres invalides.');


		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service

		if ($ok_sms == 'on') {
			$int_ok_sms = 1;
		} else {
			$int_ok_sms = 0;
		}

		if ($ok_mail == 'on') {
			$int_ok_mail = 1;
		} else {
			$int_ok_mail = 0;
		}

		$split = array();
		if (preg_match ("/([0-9]{2})\/([0-9]{2})\/([0-9]{4})/", $date_naissance, $split))
		{
			if(checkdate($split[2],$split[1],$split[3]))
			{
				$date_naissance = $split[3].'-'.$split[2].'-'.$split[1];
				//return new \SoapFault('Server','ici');
			}
			else
			{
				$date_naissance='0000-00-00';
			}
		}
		else
		{
			$date_naissance='0000-00-00';
		}

		// Formation de la requete SQL
		$sql = 'UPDATE contact
SET nom=' . $pdo->quote($nom) . ',prenom='.$pdo->quote($prenom).',date_naissance='.$pdo->quote($date_naissance).'
,civilite='.$pdo->quote($civilite).',email=' . $pdo->quote($email) . ',telephone_portable=' . $pdo->quote($telephone_portable) . '
,ok_sms='.$pdo->quote($int_ok_sms).',ok_mail='.$pdo->quote($int_ok_mail).',notes='.$pdo->quote($notes).'
		WHERE id=' . $pdo->quote($id) . '';

		$pdo->query($sql);

		return "OK";
		//return new \SoapFault('Server', $sql);

	}

	/**
	 * @Soap\Method("getNombreContactsSmsMail")
	 * @Soap\result(phpType="string")
	 */
	public function getNombreContactsSmsMailAction(){
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GNCSM001] Vous n\'avez pas les droits nécessaires.');

		$pdo = $this->container->get('bdd_service')->getPdo();
		//definition de la requête sql
		$sql='select (select count(id) from contact
where ok_sms=1 and ok_mail=0) as "ok_sms_only",
(select count(id) from contact
 where ok_mail=1 and ok_sms=1) as "ok_mail_only",
 (select count(id) from contact
 where ok_mail=1 and ok_sms=1) as "ok_sms_mail",
 (select count(id) from contact
 where ok_mail=0 and ok_sms=0)as "nok_sms_mail";';

			//return new \SoapFault('Server', $sql);
		$result = array();
		try{
			foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
				$ligne = array('ok_sms_only' => $row['ok_sms_only'], 'ok_mail_only' => $row['ok_mail_only'],
					'ok_sms_mail' => $row['ok_sms_mail'], 'nok_sms_mail' => $row['nok_sms_mail']);
				array_push($result, $ligne);
			}
			return json_encode($result);

		}
		catch (\Exception $e) {
			return new \SoapFault('Server', '[GNCSM002] Problème de connexion au serveur de base de données.');

		}


	}


	/**
	 * @Soap\Method("getNombreContactsParTrancheAge")
	 * @Soap\result(phpType="string")
	 */
	public function getNombreContactsParTrancheAgeAction(){
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GNCPTA001] Vous n\'avez pas les droits nécessaires.');

		$pdo = $this->container->get('bdd_service')->getPdo();
		//definition de la requête sql
		$sql='select (select count(id) from contact
where year(date_naissance)<>0 and (year(now())-year(date_naissance))<25) as "moins25",
(select count(id) from contact
where year(date_naissance)<>0 and (year(now())-year(date_naissance)) between 25 and 40) as "entre25_40",
(select count(id) from contact
where year(date_naissance)<>0 and (year(now())-year(date_naissance)) between 40 and 60) as "entre40_60",
(select count(id) from contact
where year(date_naissance)<>0 and (year(now())-year(date_naissance))>=60) as "plus60";';

		$result = array();
		try{
			foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
				$ligne = array('moins25' => $row['moins25'], 'entre25_40' => $row['entre25_40'],
					'entre40_60' => $row['entre40_60'], 'plus60' => $row['plus60']);
				array_push($result, $ligne);
			}
			return json_encode($result);
		}
		catch (\Exception $e) {
			return new \SoapFault('Server', '[GNCPTA002] Problème de connexion au serveur de base de données.');

		}


	}

	/**
	 * @Soap\Method("getNombreContactsParVille")
	 * @Soap\result(phpType="string")
	 */
	public function getNombreContactsParVilleAction(){
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GNCPV001] Vous n\'avez pas les droits nécessaires.');

		$pdo = $this->container->get('bdd_service')->getPdo();
		//definition de la requête sql
		$sql='select * from(select ville, count(contact.id) as "nb_personne" from contact
join adresse on adresse.ref_contact=contact.id where ville<>\'\'
group by ville)t order by nb_personne DESC LIMIT 7;';

		$result = array();
		try{
			foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
				$ligne = array('ville' => $row['ville'], 'nb_personne' => $row['nb_personne']);
				array_push($result, $ligne);
			}
			return json_encode($result);
		}
		catch (\Exception $e) {
			return new \SoapFault('Server', '[GNCPV002] Problème de connexion au serveur de base de données.');

		}
	}


	/**
	 * @Soap\Method("ajoutLigneCommandeFournisseur")
	 * @Soap\Param("commande_id",phpType="string")
	 * @Soap\Param("article_code",phpType="string")
	 * @Soap\Param("quantite_souhaite",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutLigneCommandeFournisseurAction($commande_id, $article_code, $quantite_souhaite){
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[ALCF001] Vous n\'avez pas les droits nécessaires.');
		
		if ((!is_string($commande_id) && !is_int($commande_id)) || (!is_string($article_code) && !is_int($article_code))
				|| (!is_string($quantite_souhaite) && !is_int($quantite_souhaite))) // Vérif des arguments
			return new \SoapFault('Server', '[ALCF002] Paramètres invalides.');
		
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		
		//On recupere un tableau des lignes à ajouter
		$tab_commande_id = json_decode($commande_id);
		$tab_article_code = json_decode($article_code);
		$tab_quantite_souhaite = json_decode($quantite_souhaite);
		
		//cette variable est utile pour le foreach
		//ne pas oublier de l'incrementer en fin de boucle
		$i=0;
		
		foreach ($tab_article_code as $article_code) {
			//controle, si positif on passe à l'incrementation suivante
			if($tab_commande_id[$i] == '' || $tab_quantite_souhaite[$i] < 1 || $article_code == ''){
				$i++;
				break;
			}
			$quantite_souhaite = $tab_quantite_souhaite[$i];
			//on recupere l'id de l'article pour la ligne commande et ainsi utiliser la reference de ce dernier
			$sql_a = 'SELECT MAX(id) as "max_id_article" FROM article WHERE code_barre='.$pdo->quote($article_code);
			$result_a = $pdo->query($sql_a);
			if($result_a->rowCount() == 0){
				$i++;
				break;
			}
			foreach($result_a as $row){
				$id_article = $row['max_id_article'];
			}

			//insertion des données
			$sql = 'INSERT INTO ligne_commande_fournisseur(ref_commande_fournisseur,ref_article,quantite_souhaite)
					VALUES('.$pdo->quote($tab_commande_id[0]).','.$pdo->quote($id_article).','.$pdo->quote($quantite_souhaite).')';

			$pdo->query($sql);
			$i++;
		}

		return "OK";
	}
	
	/**
	 * @Soap\Method("ajoutCommandeFournisseur")
	 * @Soap\Param("fournisseur_id",phpType="string")
	 * @Soap\Param("article_code",phpType="string")
	 * @Soap\Param("date_commande",phpType="string")
	 * @Soap\Param("quantite_souhaite",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutCommandeFournisseurAction($fournisseur_id, $article_code, $date_commande, $quantite_souhaite)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[ACF001] Vous n\'avez pas les droits nécessaires.');

		if ((!is_string($fournisseur_id) && !is_int($fournisseur_id)) || (!is_string($article_code) && !is_int($article_code))
			|| (!is_string($quantite_souhaite) && !is_int($quantite_souhaite))) // Vérif des arguments
			return new \SoapFault('Server', '[ACF002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service

		// Formation de la requete SQL
		//on recupere les parametres dans un tableau
		$tab_fournisseur_id = json_decode($fournisseur_id);
		$tab_article_code = json_decode($article_code);
		$tab_quantite_souhaite = json_decode($quantite_souhaite);
		$tab_date_commande = json_decode($date_commande);

		//verification de la date
		$split = array();
		if (preg_match ("/([0-9]{2})\/([0-9]{2})\/([0-9]{4})/", $tab_date_commande[0], $split))
		{
			if(checkdate($split[2],$split[1],$split[3]))
				$tab_date_commande[0] = $split[3].'-'.$split[2].'-'.$split[1];
			else
				$tab_date_commande[0]='0000-00-00';
		}
		else
		{
			$tab_date_commande[0]='0000-00-00';
		}
		//insertion des données
		if($tab_date_commande[0]=='0000-00-00'){
			$sql = 'INSERT INTO commande_fournisseur(ref_fournisseur, date_commande) VALUES(' . $pdo->quote($tab_fournisseur_id[0]) . ',
					NOW())';
		}
		else{
			$sql = 'INSERT INTO commande_fournisseur(ref_fournisseur, date_commande) VALUES(' . $pdo->quote($tab_fournisseur_id[0]) . ',
				   '.$pdo->quote($tab_date_commande[0]).')';
		}
		$pdo->query($sql);
		//lastinsert permet de recuperer le max id de commande
		$id_commande = $pdo->lastInsertId();

		$i = 0;
		foreach ($tab_article_code as $article_code) {
			//controle si positif passe a l'iteration suivante
			if($tab_fournisseur_id[0] == '' || $tab_quantite_souhaite[$i] < 1 || $article_code == ''){
				$i++;
				break;
			}	
			$quantite_souhaite = $tab_quantite_souhaite[$i];
			
			//on recupere l'id de l'article pour l'utiliser apres en reference FK
			$sql_a = 'SELECT MAX(id) as "max_id_article" FROM article WHERE code_barre='.$pdo->quote($article_code);
			$result_a = $pdo->query($sql_a);
			
			if($result_a->rowCount() == 0){
				$i++;
				break;
			}	
			foreach($result_a as $row){
				$id_article = $row['max_id_article'];
			}
			//////////////////////

			//insertion des données
			$sql = 'INSERT INTO ligne_commande_fournisseur(ref_commande_fournisseur,ref_article,quantite_souhaite)
					VALUES('.$pdo->quote($id_commande).','.$pdo->quote($id_article).','.$pdo->quote($quantite_souhaite).')';

			$pdo->query($sql);
			$i++;
		}

		return "OK";
	}

	/**
	 * @Soap\Method("supprCommandeFournisseur")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function supprCommandeFournisseurAction($id){
	
		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
	
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SCF001] Vous n\'avez pas les droits nécessaires.');
	
	
	
		if (!is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[SCFC002] Paramètres invalides.');
	
	
		//on "supprime" en rendant invisible la ligne dans la db
		$sql = 'UPDATE commande_fournisseur SET est_visible=0 WHERE id='.$pdo->quote($id).';';
	
		//return new \SoapFault('Server', 'coucou');
		
		//execution de la requete
		$pdo->query($sql);
	
		//return new \SoapFault('Server', $sql);
		return "OK";
	}
	
	/** @Soap\Method("getCommandesFournisseurs")
	 * @Soap\Param("count",phpType="int")
	 * @Soap\Param("offset",phpType="int")
	 * @Soap\Param("fournisseur_id",phpType="string")
	 * @Soap\Param("fournisseur_nom",phpType="string")
	 * @Soap\Param("commande_id",phpType="string")
	 * @Soap\Param("article_code",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getCommandesFournisseursAction($count, $offset, $fournisseur_id, $fournisseur_nom, $commande_id, $article_code)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GCF001] Vous n\'avez pas les droits nécessaires.');


		if ((!is_int($fournisseur_id) && !is_string($fournisseur_id)) || (!is_int($commande_id) && !is_string($commande_id))
			|| !is_int($offset) || !is_int($count) || (!is_int($article_code) && !is_string($article_code) || !is_string($fournisseur_nom))
		)// Vérif des arguments
			return new \SoapFault('Server', '[GCF002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();



		// Formation de la requete SQL
		$sql = 'select fournisseur.id as "fournisseur_id", fournisseur.nom as "fournisseur_nom",
commande_fournisseur.id as "commande_id", DATE_FORMAT(date_commande, "%d/%m/%Y") AS date_commande, code_barre,
quantite_souhaite as "quantite_souhaite",
SUM(quantite_mouvement) AS "quantite_recu" from commande_fournisseur
JOIN ligne_commande_fournisseur ON ligne_commande_fournisseur.ref_commande_fournisseur = commande_fournisseur.id
AND ligne_commande_fournisseur.est_visible=\'1\'
JOIN article on article.id = ligne_commande_fournisseur.ref_article
JOIN fournisseur ON fournisseur.id = commande_fournisseur.ref_fournisseur
LEFT OUTER JOIN reception on reception.ref_commande_fournisseur = commande_fournisseur.id
LEFT OUTER JOIN ligne_reception ON reception.id=ligne_reception.ref_reception
AND ligne_commande_fournisseur.id = ligne_reception.ref_ligne_commande
LEFT OUTER JOIN mouvement_stock ON ligne_reception.ref_mvt_stock = mouvement_stock.id ';

		/*
		 * arguments permet de rassembler sous la forme de clef valeurs tous les parametres renseignés du where
		 * ainsi on peut utiliser cette clé dans la requête sql et la valeur comme valeur du where 
		 * exemple clef = code_barre , valeur = 1234 on a WHERE code_barre = '1234'
		 */
		$arguments = array();
		$sql .= 'WHERE ';
		if (!empty($fournisseur_id) || !empty($fournisseur_nom) || !empty($commande_id) || !empty($article_code)) {

			if (!empty($fournisseur_id))
				array_push($arguments, array('commande_fournisseur.ref_fournisseur' => $fournisseur_id));
			if (!empty($commande_id))
				array_push($arguments, array('commande_fournisseur.id' => $commande_id));
			if (!empty($article_code))
				array_push($arguments, array('article.code_barre' => $article_code));
			if (!empty($fournisseur_nom))
				array_push($arguments, array('fournisseur.nom' => $fournisseur_nom));

			//$sql .= 'WHERE ';

			$i = 0;
			$taille_avant_fin = count($arguments) - 1;
			while ($i < $taille_avant_fin) {
				if(key($arguments[$i])=='commande_fournisseur.id' || key($arguments[$i])=='commande_fournisseur.ref_fournisseur')
					$val = $arguments[$i][key($arguments[$i])];
				else
					$val = '%' . $arguments[$i][key($arguments[$i])] . '%';

				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';

				$i++;
			}
			if(key($arguments[$i])=='commande_fournisseur.id' || key($arguments[$i])=='commande_fournisseur.ref_fournisseur')
				$val = $arguments[$i][key($arguments[$i])];
			else
				$val = '%' . $arguments[$i][key($arguments[$i])] . '%';

			$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';



		}
		$sql.= ' commande_fournisseur.est_visible=\'1\' ';
		$sql .= 'group by commande_id, ligne_commande_fournisseur.id HAVING (quantite_souhaite>SUM(quantite_mouvement)
		 OR quantite_recu IS NULL)';
		if ($offset != 0) {
			$sql .= ' ORDER BY commande_fournisseur.id DESC LIMIT ' . (int)$offset;
			if ($count != 0)
				$sql .= ',' . (int)$count;
		}
		else {
			$sql .= ' ORDER BY commande_fournisseur.id DESC ';
		}

		//return new \SoapFault('Server', $sql);
		
		//id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('fournisseur_id'=>$row['fournisseur_id'],'fournisseur_nom' => $row['fournisseur_nom'], 'commande_id' => $row['commande_id'],
				'date_commande' => $row['date_commande'], 'code_barre' => $row['code_barre'],
				'quantite_souhaite'=>$row['quantite_souhaite'],'quantite_recu'=>$row['quantite_recu']);
			array_push($result, $ligne);
		}
		return json_encode($result);
		//return new \SoapFault('Server', $sql);
	}


	/** @Soap\Method("getLignesCommandesFournisseurs")
	 * @Soap\Param("count",phpType="int")
	 * @Soap\Param("offset",phpType="int")
	 * @Soap\Param("fournisseur_id",phpType="string")
	 * @Soap\Param("fournisseur_nom",phpType="string")
	 * @Soap\Param("commande_id",phpType="string")
	 * @Soap\Param("article_code",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getLignesCommandesFournisseursAction($count, $offset, $fournisseur_id, $fournisseur_nom, $commande_id, $article_code)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GLCF001] Vous n\'avez pas les droits nécessaires.');


		if ((!is_int($fournisseur_id) && !is_string($fournisseur_id)) || (!is_int($commande_id) && !is_string($commande_id))
			|| !is_int($offset) || !is_int($count) || (!is_int($article_code) && !is_string($article_code) || !is_string($fournisseur_nom))
		)// Vérif des arguments
			return new \SoapFault('Server', '[GLCF002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();



		// Formation de la requete SQL
		$sql = 'select fournisseur.id as "fournisseur_id", fournisseur.nom as "fournisseur_nom", commande_fournisseur.id as "commande_id", date_commande, code_barre,
ligne_commande_fournisseur.id as "ligne_commande_id", quantite_souhaite as "quantite_souhaite",
SUM(quantite_mouvement) AS "quantite_recu" from commande_fournisseur
JOIN ligne_commande_fournisseur ON ligne_commande_fournisseur.ref_commande_fournisseur = commande_fournisseur.id
AND ligne_commande_fournisseur.est_visible=\'1\'
JOIN article on article.id = ligne_commande_fournisseur.ref_article
JOIN fournisseur ON fournisseur.id = commande_fournisseur.ref_fournisseur
LEFT OUTER JOIN reception on reception.ref_commande_fournisseur = commande_fournisseur.id
LEFT OUTER JOIN ligne_reception ON reception.id=ligne_reception.ref_reception
AND ligne_commande_fournisseur.id = ligne_reception.ref_ligne_commande
LEFT OUTER JOIN mouvement_stock ON ligne_reception.ref_mvt_stock = mouvement_stock.id ';
		
		/*
		 * arguments permet de rassembler sous la forme de clef valeurs tous les parametres renseignés du where
		 * ainsi on peut utiliser cette clé dans la requête sql et la valeur comme valeur du where
		 * exemple clef = code_barre , valeur = 1234 on a WHERE code_barre = '1234'
		 */
		$arguments = array();
		if (!empty($fournisseur_id) || !empty($fournisseur_nom) || !empty($commande_id) || !empty($article_code)) {

			if (!empty($fournisseur_id))
				array_push($arguments, array('commande_fournisseur.ref_fournisseur' => $fournisseur_id));
			if (!empty($commande_id))
				array_push($arguments, array('commande_fournisseur.id' => $commande_id));
			if (!empty($article_code))
				array_push($arguments, array('article.code_barre' => $article_code));
			if (!empty($fournisseur_nom))
				array_push($arguments, array('fournisseur.nom' => $fournisseur_nom));

			$sql .= 'WHERE ';

			$i = 0;
			$taille_avant_fin = count($arguments) - 1;
			while ($i < $taille_avant_fin) {
				if(key($arguments[$i])=='commande_fournisseur.id' || key($arguments[$i])=='commande_fournisseur.ref_fournisseur')
					$val = $arguments[$i][key($arguments[$i])];
				else
					$val = '%' . $arguments[$i][key($arguments[$i])] . '%';

				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';

				$i++;
			}
			if(key($arguments[$i])=='commande_fournisseur.id' || key($arguments[$i])=='commande_fournisseur.ref_fournisseur')
				$val = $arguments[$i][key($arguments[$i])];
			else
				$val = '%' . $arguments[$i][key($arguments[$i])] . '%';

			$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND commande_fournisseur.est_visible=\'1\'';



		}

		$sql .= 'group by commande_id, ligne_commande_fournisseur.id HAVING (quantite_souhaite>SUM(quantite_mouvement)
		 OR quantite_recu IS NULL)';
		if ($offset != 0) {
			$sql .= ' ORDER BY date_commande DESC, commande_fournisseur.id ASC, fournisseur.nom ASC LIMIT ' . (int)$offset;
			if ($count != 0)
				$sql .= ',' . (int)$count;
		}
		else {
			$sql .= ' ORDER BY date_commande DESC, commande_fournisseur.id ASC, fournisseur.nom ASC ';
		}

		//return new \SoapFault('Server', $sql);
		
		//id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('fournisseur_id'=>$row['fournisseur_id'],'fournisseur_nom' => $row['fournisseur_nom'], 'commande_id' => $row['commande_id'],
				'date_commande' => $row['date_commande'], 'code_barre' => $row['code_barre'],
				'ligne_commande_id'=>$row['ligne_commande_id'],'quantite_souhaite'=>$row['quantite_souhaite'],'quantite_recu'=>$row['quantite_recu']);
			array_push($result, $ligne);
		}
		return json_encode($result);
		//return new \SoapFault('Server', $sql);
	}

	/**
	 * @Soap\Method("modifCommandeFournisseur")
	 * @Soap\Param("fournisseur_id",phpType="string")
	 * @Soap\Param("commande_id",phpType="string")
	 * @Soap\Param("ligne_commande_id",phpType="string")
	 * @Soap\Param("date_commande",phpType="string")
	 * @Soap\Param("article_code",phpType="string")
	 * @Soap\Param("quantite_souhaite",phpType="string")
	 * @Soap\Param("est_visible",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifCommandeFournisseurAction($fournisseur_id, $commande_id, $ligne_commande_id, $date_commande,$article_code,
												   $quantite_souhaite, $est_visible)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MCF001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($fournisseur_id) || !is_string($article_code) || !is_string($commande_id) || !is_string($quantite_souhaite)
			|| !is_string($ligne_commande_id) || !is_string($est_visible)) // Vérif des arguments
			return new \SoapFault('Server', '[MCF002] Paramètres invalides.');


		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service

		//on recupere tous les parametres sous forme de tableau
		$tab_fournisseur_id = json_decode($fournisseur_id);
		$tab_commande_id = json_decode($commande_id);
		$tab_ligne_commande_id = json_decode($ligne_commande_id);
		$tab_article_code = json_decode($article_code);
		$tab_quantite_souhaite = json_decode($quantite_souhaite);
		$tab_date_commande = json_decode($date_commande);
		$tab_est_visible = json_decode($est_visible);

		//premiere etape on met a jour la commande
		$sql_c = 'UPDATE commande_fournisseur SET date_commande='.$pdo->quote($tab_date_commande[0]).',
		ref_fournisseur='.$pdo->quote($tab_fournisseur_id[0]).' WHERE
		id='.$pdo->quote($tab_commande_id[0]).';';

		$pdo->query($sql_c);

		//i = variable d'incrementation
		$i = 0;
		foreach($tab_article_code as $article_code){
			//on recupere le max id de l'article pour s'en servir comme reference
			$sql_a = 'select MAX(id) as "max_id" from article where code_barre='.$pdo->quote($article_code).';';
			
			foreach($pdo->query($sql_a) as $ligne){
				$article_id = $ligne['max_id'];
			}

			//on met a jour les lignes commandes fournisseurs
			$sql = 'UPDATE ligne_commande_fournisseur SET ref_article='.$pdo->quote($article_id).'
		, quantite_souhaite='.$pdo->quote($tab_quantite_souhaite[$i]).', est_visible='.$pdo->quote($tab_est_visible[$i]).'
		 WHERE id='.$pdo->quote($tab_ligne_commande_id[$i]).';';

			//return new \SoapFault('Server', $sql);

			$pdo->query($sql);

			$i++;
		}

		// Formation de la requete SQL


		return "OK";
		//return new \SoapFault('Server', $sql);

	}


	/** @Soap\Method("getAllCommandesFournisseurs")
	 * @Soap\Param("count",phpType="int")
	 * @Soap\Param("offset",phpType="int")
	 * @Soap\Param("fournisseur_id",phpType="string")
	 * @Soap\Param("fournisseur_nom",phpType="string")
	 * @Soap\Param("commande_id",phpType="string")
	 * @Soap\Param("article_code",phpType="string")
	 * @Soap\Param("date_deb",phpType="string")
	 * @Soap\Param("date_fin",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getAllCommandesFournisseursAction($count, $offset, $fournisseur_id, $fournisseur_nom,
													  $commande_id, $article_code, $date_deb, $date_fin)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[GACF001] Vous n\'avez pas les droits nécessaires.');


		if ((!is_int($fournisseur_id) && !is_string($fournisseur_id)) || (!is_int($commande_id) && !is_string($commande_id))
			|| !is_int($offset) || !is_int($count) || (!is_int($article_code) && !is_string($article_code) || !is_string($fournisseur_nom))
		)// Vérif des arguments
			return new \SoapFault('Server', '[GACF002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();



		// Formation de la requete SQL
		$sql = 'select fournisseur.id as "fournisseur_id", fournisseur.nom as "fournisseur_nom",
commande_fournisseur.id as "commande_id",  DATE_FORMAT(date_commande, "%d/%m/%Y") AS date_commande, code_barre,
quantite_souhaite as "quantite_souhaite",
SUM(quantite_mouvement) AS "quantite_recu" from commande_fournisseur
JOIN ligne_commande_fournisseur ON ligne_commande_fournisseur.ref_commande_fournisseur = commande_fournisseur.id
AND ligne_commande_fournisseur.est_visible=\'1\'
JOIN article on article.id = ligne_commande_fournisseur.ref_article
JOIN fournisseur ON fournisseur.id = commande_fournisseur.ref_fournisseur
LEFT OUTER JOIN reception on reception.ref_commande_fournisseur = commande_fournisseur.id
LEFT OUTER JOIN ligne_reception ON reception.id=ligne_reception.ref_reception
AND ligne_commande_fournisseur.id = ligne_reception.ref_ligne_commande
LEFT OUTER JOIN mouvement_stock ON ligne_reception.ref_mvt_stock = mouvement_stock.id ';
		

		/*
		 * arguments permet de rassembler sous la forme de clef valeurs tous les parametres renseignés du where
		 * ainsi on peut utiliser cette clé dans la requête sql et la valeur comme valeur du where
		 * exemple clef = code_barre , valeur = 1234 on a WHERE code_barre = '1234'
		 */
		$arguments = array();
		if (!empty($fournisseur_id) || !empty($fournisseur_nom) || !empty($commande_id) || !empty($article_code)
		 || !empty($date_deb) || !empty($date_fin)) {

			if (!empty($fournisseur_id))
				array_push($arguments, array('commande_fournisseur.ref_fournisseur' => $fournisseur_id));
			if (!empty($commande_id))
				array_push($arguments, array('commande_fournisseur.id' => $commande_id));
			if (!empty($article_code))
				array_push($arguments, array('article.code_barre' => $article_code));
			if (!empty($fournisseur_nom))
				array_push($arguments, array('fournisseur.nom' => $fournisseur_nom));
			if (!empty($date_deb)){
				//verification de la date
				$split = array();
				
				if (preg_match ("/([0-9]{2})\/([0-9]{2})\/([0-9]{4})/", $date_deb, $split))
				{
					if(checkdate($split[2],$split[1],$split[3]))
					{
						$date_deb = $split[3].'-'.$split[2].'-'.$split[1];
						//return new \SoapFault('Server','ici');
					}
					else
					{
						return new \SoapFault('Server', '[GACF003] Date début période invalide.');
					}
				}
				else
				{
					return new \SoapFault('Server', '[GACF003] Date début période invalide.');
				}
				
			
				array_push($arguments,array('date_debut'=>$date_deb));
			}
			if (!empty($date_fin)){
				//verification de la date
				$split = array();
				if (preg_match ("/([0-9]{2})\/([0-9]{2})\/([0-9]{4})/", $date_fin, $split))
				{
					if(checkdate($split[2],$split[1],$split[3]))
					{
						$date_fin = $split[3].'-'.$split[2].'-'.$split[1];
						//return new \SoapFault('Server','ici');
					}
					else
					{
						return new \SoapFault('Server', '[GACF004] Date fin période invalide.');
					}
				}
				else
				{
					return new \SoapFault('Server', '[GACF004] Date fin période invalide.');
				}
				array_push($arguments,array('date_fin'=>$date_fin));
			}

			$sql .= 'WHERE ';

			$i = 0;
			$taille_avant_fin = count($arguments) - 1;
			while ($i < $taille_avant_fin) {
				if(key($arguments[$i])=='commande_fournisseur.id' || key($arguments[$i])=='commande_fournisseur.ref_fournisseur'){
					$val = $arguments[$i][key($arguments[$i])];
					$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';
				}
				elseif(key($arguments[$i]) == 'date_debut'){
					$sql .=' date_commande >= '.$pdo->quote($date_deb).' AND';
				}
				elseif(key($arguments[$i]) == 'date_fin'){
					$sql .=' date_commande < '.$pdo->quote($date_fin).' AND';
				}
				else{
					$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
					$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';
				}

				$i++;
			}
			if(key($arguments[$i])=='commande_fournisseur.id' || key($arguments[$i])=='commande_fournisseur.ref_fournisseur'){
				$val = $arguments[$i][key($arguments[$i])];
				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND commande_fournisseur.est_visible=\'1\'';
			}
			elseif(key($arguments[$i]) == 'date_debut'){
				$sql .=' date_commande >= '.$pdo->quote($date_deb).' AND commande_fournisseur.est_visible=\'1\'';
			}
			elseif(key($arguments[$i]) == 'date_fin'){
				$sql .=' date_commande < '.$pdo->quote($date_fin).' AND commande_fournisseur.est_visible=\'1\'';
			}
			else{
				$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND commande_fournisseur.est_visible=\'1\'';
			}
		}

		$sql .= 'group by commande_id, ligne_commande_fournisseur.id HAVING (quantite_souhaite<=SUM(quantite_mouvement)
		 AND quantite_recu IS NOT NULL)';

		if ($offset != 0) {
			$sql .= ' ORDER BY date_commande DESC, commande_fournisseur.id ASC, fournisseur.nom ASC LIMIT ' . (int)$offset;
			if ($count != 0)
				$sql .= ',' . (int)$count;
		}
		else {
			$sql .= ' ORDER BY date_commande DESC, commande_fournisseur.id ASC, fournisseur.nom ASC ';
		}

		//return new \SoapFault('Server', $sql);
		
		//id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('fournisseur_id'=>$row['fournisseur_id'],'fournisseur_nom' => $row['fournisseur_nom'], 'commande_id' => $row['commande_id'],
				'date_commande' => $row['date_commande'], 'code_barre' => $row['code_barre'],
				'quantite_souhaite'=>$row['quantite_souhaite'],'quantite_recu'=>$row['quantite_recu']);
			array_push($result, $ligne);
		}
		return json_encode($result);
		//return new \SoapFault('Server', $sql);
	}


	/**
	 * @Soap\Method("ajoutReceptionCommande")
	 * @Soap\Param("commande_id",phpType="string")
	 * @Soap\Param("ligne_commande_id",phpType="string")
	 * @Soap\Param("article_code",phpType="string")
	 * @Soap\Param("quantite",phpType="string")
	 * @Soap\Param("date_reception",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutReceptionCommandeAction($commande_id,$ligne_commande_id, $article_code, $quantite, $date_reception)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[ACF001] Vous n\'avez pas les droits nécessaires.');



		if (!is_string($ligne_commande_id) || !is_string($article_code)
			|| !is_string($quantite) || !is_string($date_reception)) // Vérif des arguments
			return new \SoapFault('Server', '[ACF002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service

		// Formation de la requete SQL
		//on recupere tous les parametres sous forme de tableau
		$tab_commande_id = json_decode($commande_id);
		$tab_ligne_commande_id = json_decode($ligne_commande_id);
		$tab_article_code = json_decode($article_code);
		$tab_quantite = json_decode($quantite);
		$tab_date_reception = json_decode($date_reception);
		//return new \SoapFault('Server', $tab_fournisseur_id[0]);

		foreach($tab_article_code as $article_code){

			$sql = 'SELECT * FROM article WHERE code_barre='.$pdo->quote($article_code).';';
			$resultat = $pdo->query($sql);

			if ($resultat->rowCount() == 0) {
				return new \SoapFault('Server', '[ACF003] Article '.$article_code.' invalide.');
			}
		}
		//verification de la date
		$split = array();
		if (preg_match ("/([0-9]{2})\/([0-9]{2})\/([0-9]{4})/", $tab_date_reception[0], $split))
		{
			if(checkdate($split[2],$split[1],$split[3]))
			{
				$tab_date_reception[0] = $split[3].'-'.$split[2].'-'.$split[1];
				//return new \SoapFault('Server','ici');
			}
			else
			{
				$tab_date_reception[0]='0000-00-00';
			}
		}
		else
		{
			$tab_date_reception[0]='0000-00-00';
		}

		//insertion des données
		if($tab_date_reception[0]=='0000-00-00'){
			$sql = 'INSERT INTO reception(ref_commande_fournisseur, date_reception) VALUES(' . $pdo->quote($tab_commande_id[0]) . ',
		NOW())';
		}
		else{
			$sql = 'INSERT INTO reception(ref_commande_fournisseur, date_reception) VALUES(' . $pdo->quote($tab_commande_id[0]) . ',
		'.$pdo->quote($tab_date_reception[0]).')';
		}


		//return new \SoapFault('Server', $sql);
		$pdo->query($sql);

		//variable d'incrementation
		$i = 0;
		foreach ($tab_ligne_commande_id as $ligne_commande_id) {
			$article_code = $tab_article_code[$i];
			$quantite = $tab_quantite[$i];

			if(!empty($quantite) && $quantite >= 1){

				//on recupere l'id de la reception
				$sql_f = 'SELECT MAX(id) as "max_id" FROM reception;';
				//on recupere l'id de l'article
				$sql_a = 'SELECT MAX(id) as "max_id_article" FROM article WHERE code_barre='.$pdo->quote($article_code).';';
				//on recupere le future id du mvt stock
				$sql_mvt = 'SELECT MAX(id) as "max_id_mvt" FROM mouvement_stock;';
				//result reception

				//return new \SoapFault('Server','[AA00011] '.$sql_mvt.'.');
				//return new \SoapFault('Server','[AA00011] '.$sql_f.'.');
				//return new \SoapFault('Server','[AA00011] '.$sql_a.'.');

				$result_f = $pdo->query($sql_f);
				$result_mvt = $pdo->query($sql_mvt);

				foreach($pdo->query($sql_a) as $row){
					$id_article = $row['max_id_article'];
				}

				//return new \SoapFault('Server','[AA00011] Apres l\'id article');
				if($result_f->rowCount() == 0) {
					$id_reception = 1;
				}
				else{
					foreach($result_f as $row){
						$id_reception = $row['max_id'];
					}
				}
				//return new \SoapFault('Server','[AA00011] Apres l\'id reception : '.$id_reception.'');
				if($result_mvt->rowCount() == 0)
					$id_mvt = 1;
				else{
					foreach($result_mvt as $row){
						$id_mvt = $row['max_id_mvt'];
					}
					$id_mvt++;
				}
				//return new \SoapFault('Server','[AA00011] Apres l\'id mvt stock : '.$id_mvt.'');
				//insertion mouvement stock
				$sql_mvt = ' INSERT INTO `alba`.`mouvement_stock`
(`ref_article`,
`quantite_mouvement`,
`date_mouvement`)
VALUES
('.$pdo->quote($id_article).',
'.$pdo->quote($quantite).',
NOW());';

				$pdo->query($sql_mvt);
				//return new \SoapFault('Server','[AA00011] '.$sql_mvt.'.');

				//insertion ligne reception
				$sql_lrecep = 'INSERT INTO `alba`.`ligne_reception`
(`ref_article`,
`ref_mvt_stock`,
`ref_reception`,
`ref_ligne_commande`)
VALUES
('.$pdo->quote($id_article).',
'.$pdo->quote($id_mvt).',
'.$pdo->quote($id_reception).',
'.$pdo->quote($ligne_commande_id).');';
				//insertion des données
				$pdo->query($sql_lrecep);
				//return new \SoapFault('Server','[AA00011] '.$sql.'.');

			}

			$i++;
		}

		return "OK";
	}


}