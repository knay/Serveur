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

		$sql = 'INSERT INTO facture (date_facture, est_visible, ref_contact) VALUE (NOW(), true, '.(int)$tabArticles->idClient.')';
		
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
	 * TODO
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
		if (!is_string($nom)) // Vérif des arguments
			return new \SoapFault('Server', '[ALP002] Paramètres invalides.');
		try {
			$pdo = $this->container->get('bdd_service')->getPdo();
			//on verifie si il y a deja la ligne produit
			$sql = 'SELECT * FROM ligne_produit WHERE nom=' . $pdo->quote($nom) . '';

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
	 * @Soap\Param("attribut_nom",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function getLigneProduitAction($count, $offset, $nom, $attribut_nom)
	{


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
			$sql .= 'WHERE ligne_produit.nom LIKE ' . $pdo->quote($nom) . ' AND attribut.nom LIKE '.$pdo->quote($attribut_nom).'';
		}

		if (!empty($nom) && empty($attribut_nom)){
			$nom = '%'.$nom.'%';
			$sql .= 'WHERE ligne_produit.nom LIKE ' . $pdo->quote($nom) . ' ';
		}

		if (empty($nom) && !empty($attribut_nom)){
			$attribut_nom = '%'.$attribut_nom.'%';
			$sql .= 'WHERE attribut.nom LIKE ' . $pdo->quote($attribut_nom) . ' ';
		}
		$sql.= 'group by ligne_produit.id,ligne_produit.nom';
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
			$code_barre = $article->codeBarre;
			$nom_produit = $article->produit;
			$quantite = $article->quantite;
			$attributs = $article->attributs;

			if ($avecPrix)
				$prixClient = $article->prix;

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
							VALUE (\'' . $idArticle . '\', 0, \'' . (float)$prixClient . '\', NOW())';
				$resultat = $pdo->query($sql);
			}

			$sql = 'INSERT INTO mouvement_stock (ref_article, quantite_mouvement, date_mouvement, est_inventaire)
					VALUES (\'' . $idArticle . '\', ' . $quantite . ', NOW(), TRUE)'; // Insertion du mouvement de stock
			$resultat = $pdo->query($sql);

			$sql = 'DELETE FROM article_a_pour_val_attribut WHERE ref_article=\'' . $idArticle . '\'';
			$resultat = $pdo->query($sql); // On vide la table de correspondance pour cet article

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

		$sql = 'SELECT produit.nom AS nomProduit, attribut.nom AS nomAttribut, valeur_attribut.libelle AS nomValAttribut
				FROM article
				JOIN produit ON article.ref_produit = produit.id
				JOIN article_a_pour_val_attribut ON article_a_pour_val_attribut.ref_article = article.id
				JOIN valeur_attribut ON article_a_pour_val_attribut.ref_val_attribut = valeur_attribut.id
				JOIN attribut ON valeur_attribut.ref_attribut = attribut.id
				WHERE article.code_barre = ' . $pdo->quote($codeBarre);
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
				$sql = 'SELECT a.id AS aid, nom, v.libelle, a.est_visible FROM attribut a
				        JOIN valeur_attribut v ON v.ref_attribut=a.id 
						WHERE a.est_visible = TRUE AND v.est_visible = TRUE ';

				if (!empty($nom)) {
					$nom = '%' . $nom . '%';
					$sql .= ' AND a.nom LIKE '.$pdo->quote($nom).' OR v.libelle LIKE '.$pdo->quote($nom);
				}

				$sql .= ' GROUP BY libelle ORDER BY nom ASC';

				$tabAttributs = array();
				$dernierNom = '';
				$dernierId = 0;
				foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
					if ($dernierNom !== $row['nom']) {
						$ligne = array('id' => $dernierId, 'nom' => $dernierNom, 'attributs' => $tabAttributs);
						array_push($result, $ligne);
						$tabAttributs = array();
					}
					$dernierLibelle = $row['libelle'];
					$dernierNom = $row['nom'];
					$dernierId = $row['aid'];
					array_push($tabAttributs, $row['libelle']);
				}
				$ligne = array('id' => $dernierId, 'nom' => $dernierNom, 'attributs' => $tabAttributs);
				array_push($result, $ligne);
			} else {
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
		// TODO faire la recherche par ligne produit
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
				WHERE CONCAT (nom, prenom, date_naissance, email, telephone_portable) 
				REGEXP replace('.$pdo->quote($critere).', \' \', \'|\')';
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
		$sql = 'SELECT montant_client FROM prix JOIN article ON ref_article=article.id WHERE code_barre=' . $pdo->quote($codeBarre);
		$resultat = $pdo->query($sql);

		$prix = 0;
		foreach ($resultat as $row) {
			$prix = $row["montant_client"];
		}

		return '' . $prix;
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
		if (!is_string($nom) || !is_string($ligneProduit)) // Vérif des arguments
			return new \SoapFault('Server', '[ALP002] Paramètres invalides.');

		try {

			$pdo = $this->container->get('bdd_service')->getPdo();

			//on verifie si il y a deja le produit
			$sql = 'SELECT * FROM produit JOIN ligne_produit ON produit.ref_ligne_produit = ligne_produit.id
			WHERE produit.nom=' . $pdo->quote($nom) . ' AND ligne_produit.nom=' . $pdo->quote($ligneProduit) . '';

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
			$sql .= 'WHERE produit.nom LIKE ' . $pdo->quote($nom) . ' AND ligne_produit.nom LIKE ' . $pdo->quote($ligneproduit) . '
			 AND (attribut.nom LIKE '.$pdo->quote($attribut).' OR valeur_attribut.libelle LIKE '.$pdo->quote($attribut).')';
		}

		elseif (empty($nom) && !empty($ligneproduit) && empty($attribut)){
			$ligneproduit= '%'.$ligneproduit.'%';
			$sql .= 'WHERE ligne_produit.nom LIKE ' . $pdo->quote($ligneproduit) . '';
		}
		elseif (!empty($nom) && empty($ligneproduit) && empty($attribut)){
			$nom = '%'.$nom.'%';
			$sql .= 'WHERE produit.nom LIKE ' . $pdo->quote($nom) . '';
		}
		elseif (empty($nom) && empty($ligneproduit) && !empty($attribut)){
			$attribut = '%'.$attribut.'%';
			$sql .= 'WHERE (attribut.nom LIKE '.$pdo->quote($attribut).' OR valeur_attribut.libelle LIKE '.$pdo->quote($attribut).')';
		}
		elseif (!empty($nom) && !empty($ligneproduit) && empty($attribut)){
			$ligneproduit= '%'.$ligneproduit.'%';
			$nom = '%'.$nom.'%';
			$sql .= 'WHERE ligne_produit.nom LIKE ' . $pdo->quote($ligneproduit) . '
			AND produit.nom LIKE '.$pdo->quote($nom).'';
		}
		elseif (empty($nom) && !empty($ligneproduit) && !empty($attribut)){
			$ligneproduit = '%'.$ligneproduit.'%';
			$attribut = '%'.$attribut.'%';
			$sql .= 'WHERE ligne_produit.nom LIKE ' . $pdo->quote($ligneproduit) . '
			AND (attribut.nom LIKE '.$pdo->quote($attribut).' OR valeur_attribut.libelle LIKE '.$pdo->quote($attribut).')';
		}
		elseif (!empty($nom) && empty($ligneproduit) && !empty($attribut)){
			$nom = '%'.$nom.'%';
			$sql .= 'WHERE produit.nom LIKE ' . $pdo->quote($nom) . '
			AND (attribut.nom LIKE '.$pdo->quote($attribut).' OR valeur_attribut.libelle LIKE '.$pdo->quote($attribut).')';
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
			return new \SoapFault('Server', '[LP001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom_lp) || !is_int($id_p) || !is_string($nom_p)) // Vérif des arguments
			return new \SoapFault('Server', '[LP002] Paramètres invalides.');

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
				array('menu' => 'client','sous_menu' => array('Informations client', 'Statistiques')),
				array('menu' => 'evenement','sous_menu' => array()),
				array('menu' => 'fournisseur','sous_menu' => array('Commandes','Fournisseurs','Historique')),
				array('menu' => 'produit','sous_menu' => array('Articles', 'Attributs','Lignes produits','Produits','Réception','Stock','Inventaire', 'Génération de codes barres')),
				array('menu' => 'vente','sous_menu' => array('Moyens de paiement','Statistiques','Factures','Retour')));
			return json_encode($tableau_menu);
		} // Si il est employe
		else if ($this->container->get('user_service')->isOk('ROLE_EMPLOYE')) {
			//TODO
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
			return new SoapFault('Server', '[GPFLP002] Paramètres invalides.');

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
			return new SoapFault('Server', '[GS002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		$requete_stock = 'SELECT l.nom as ligne_produit_nom,p.nom as produit_nom,a.code_barre article_code_barre,a.id as id_article FROM alba.ligne_produit l
						INNER JOIN alba.produit p ON p.ref_ligne_produit = l.id
						INNER JOIN alba.article a ON a.ref_produit = p.id';

		// Si l'article est renseigner. Pas de else if car l'utilisateur peut tres bien
		// selection une ligne produit puis finalement s�lectionner biper un artcile.
		// et on donnne la priorit� a l'article!

		if (!empty($Article)) {
			$requete_stock = $requete_stock . ' WHERE a.code_barre = ' . $pdo->quote($Article) . '';
		} //Si le parametre ligne de produit n'est pas vide
		else if (!empty($LigneProduit)) {
			// On verifie si l'utilisateur a selectionner un produit
			// Si oui on fait la recherche par rapport a ce produit et non a la ligne produit
			if (!empty($Produit)) {
				$requete_stock = $requete_stock . ' WHERE p.nom = ' . $pdo->quote($Produit) . '';
			} // sinon on recherche par la ligne produit
			else {
				$requete_stock = $requete_stock . ' WHERE l.nom = ' . $pdo->quote($LigneProduit) . '';
			}
		} else {
			$requete_stock = $requete_stock;
		}

		$requete_stock = $requete_stock . ' ORDER BY ligne_produit_nom,produit_nom ASC';

		foreach ($pdo->query($requete_stock) as $row_ligne) {
			$sql_quantite_article = 'SELECT SUM(quantite_mouvement) as total_mouvement FROM alba.mouvement_stock
															WHERE ref_article = ' . $row_ligne['id_article'] . ' AND date_mouvement >= (SELECT date_mouvement FROM alba.mouvement_stock
															WHERE ref_article = ' . $row_ligne['id_article'] . '
															AND est_inventaire = 1
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

		$requete_tous_les_produits = 'SELECT nom as ligne_produit_nom FROM alba.ligne_produit ORDER BY nom ASC';

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
			return new SoapFault('Server', '[GAF002] Paramètres invalides.');
		
		
		$requete_toutes_les_lignes_factures = 'SELECT id_facture, date_de_facture, nom_contact,
						SUM(CASE 
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) AS montant
				        FROM( SELECT * FROM ventes_contact';
				      

		if($date != ''){
			$requete_toutes_les_lignes_factures = $requete_toutes_les_lignes_factures .' WHERE f.date_facture > ' . $pdo->quote($date) . '';
		}
		if($client != ''){
			$requete_toutes_les_lignes_factures = $requete_toutes_les_lignes_factures .' WHERE c.nom = ' . $pdo->quote($client) . '';
		}
		
		//On ajoute a la requete la fin
		$requete_toutes_les_lignes_factures = $requete_toutes_les_lignes_factures . ' ) t GROUP BY id_facture ORDER BY id_facture DESC';
		
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
	
		if (!is_int($numero) ) // Vérif des arguments
			return new SoapFault('Server', '[GDFOF002] Paramètres invalides.');
	
	
		$requete_detail_factures = 'SELECT id_facture ,nom_produit , date_de_facture, nom_contact, prenom_contact, nom_article ,article_id , nb_article, prix_id ,reduction_article,
						SUM(CASE
							WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction_article/100)*(-1*nb_article)
							WHEN type_reduction = \'remise\' THEN (montant_client-reduction_article)*(-1*nb_article)
							ELSE montant_client*(-1*nb_article)
						END) AS montant,adresse_numero,adresse_rue,adresse_code_postal,adresse_ville,adresse_pays
				        FROM(
				        SELECT 
							   lf.id as "ligne_facture_id",
				               a.id as "article_id",
							   a.code_barre as "nom_article",
				               px.montant_client as "prix_id",
				               lf.id as "id_ligne_facture" ,
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
							   pt.nom as "nom_produit"
				      
				        FROM facture f
						JOIN ligne_facture lf ON lf.ref_facture = f.id 
						JOIN mouvement_stock m ON lf.ref_mvt_stock = m.id
						JOIN article a ON m.ref_article = a.id
				        JOIN prix px ON px.ref_article = a.id AND px.id = 
                        (SELECT MAX(prix.id) FROM prix WHERE prix.date_modif<f.date_facture AND prix.ref_article=a.id)
				        JOIN produit pt ON a.ref_produit = pt.id
				        LEFT OUTER JOIN remise r ON lf.ref_remise = r.id
				        LEFT OUTER JOIN contact c ON f.ref_contact = c.id
						RIGHT OUTER JOIN adresse ad ON c.id = ad.ref_contact
						
						WHERE f.id = '.$pdo->quote($numero).'
						 ) t GROUP BY ligne_facture_id ORDER BY id_facture ASC';
	
		foreach ($pdo->query($requete_detail_factures) as $row) {
			$nombre_article = substr($row['nb_article'],1);
			$ligne = array('numero_facture' => $row['id_facture'],
					'date_facture'=>$row['date_de_facture'],
					'nom_produit'=>$row['nom_produit'],
					'nom_client'=>$row['nom_contact'],
					'prenom_client'=>$row['prenom_contact'],
					'adresse_numero'=>$row['adresse_numero'],
					'adresse_rue'=>$row['adresse_rue'],
					'adresse_code_postal'=>$row['adresse_code_postal'],
					'adresse_ville'=>$row['adresse_ville'],
					'adresse_pays'=>$row['adresse_pays'],
					'nom_article'=>$row['nom_article'],
					'nombre_article'=>$nombre_article,
					'prix_article'=>$row['prix_id'],
					'reduction_article'=>$row['reduction_article'],
					'montant_facture'=>$row['montant']);
			array_push($result, $ligne);
		}
		return json_encode($result);
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

			$sql .= 'WHERE ';

			$i = 0;
			$taille_avant_fin = count($arguments) - 1;
			while ($i < $taille_avant_fin) {

				$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';

				$i++;
			}
			$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
			$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND est_visible=\'1\'';



		}

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

		if (!is_string($nom)) // Vérif des arguments
			return new \SoapFault('Server', '[AF002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		//$result = array();

		// Formation de la requete SQL
		$sql = 'SELECT id, nom, email, telephone_portable, reference_client FROM fournisseur WHERE
nom=' . $pdo->quote($nom) . ' AND email='.$pdo->quote($email).' AND telephone_portable='.$pdo->quote($telephone_portable).'
 AND reference_client='.$pdo->quote($reference_client).'';
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
		$result = array();

		// Formation de la requete SQL
		$sql = 'UPDATE fournisseur SET nom=' . $pdo->quote($nom) . ',email=' . $pdo->quote($email) . '
		,telephone_portable=' . $pdo->quote($telephone_portable) . ',reference_client='.$pdo->quote($reference_client).'
		,notes='.$pdo->quote($notes).' WHERE id=' . $pdo->quote($id) . '';

		$resultat = $pdo->query($sql);
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
		$sql = 'SELECT id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe FROM adresse ';

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
			$ligne = array('id' => $row['id'], 'pays' => $row['pays'], 'ville' => $row['ville'], 'voie' => $row['voie'],
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
	 * @Soap\Result(phpType = "string")
	 */
	public function ajoutAdresseAction($est_fournisseur, $ref_id, $pays, $ville, $voie, $num_voie, $code_postal, $num_appartement, $telephone_fixe)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[AA001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($pays) || !is_string($ville) || !is_string($voie) || !is_string($num_voie) || !is_string($code_postal)
			|| !is_string($num_appartement) || !is_string($telephone_fixe)
			|| !is_bool($est_fournisseur) || (!is_string($ref_id) && !is_int($ref_id))
		) // Vérif des arguments
			return new \SoapFault('Server', '[AA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		// Formation de la requete SQL
		$tab_pays = json_decode($pays);
		$tab_ville = json_decode($ville);
		$tab_voie = json_decode($voie);
		$tab_num_voie = json_decode($num_voie);
		$tab_code_postal = json_decode($code_postal);
		$tab_num_appartement = json_decode($num_appartement);
		$tab_telephone_fixe = json_decode($telephone_fixe);

		$i = 0;
		foreach ($tab_pays as $pays) {

			$ville = $tab_ville[$i];
			$voie = $tab_voie[$i];
			$num_voie = $tab_num_voie[$i];
			$code_postal = $tab_code_postal[$i];
			$num_appartement = $tab_num_appartement[$i];
			$telephone_fixe = $tab_telephone_fixe[$i];


			$sql = 'SELECT id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe FROM adresse
WHERE pays=' . $pdo->quote($pays) . ' AND ville=' . $pdo->quote($ville) . ' AND voie=' . $pdo->quote($voie) . '
AND num_voie=' . $pdo->quote($num_voie) . ' ';

			if ($est_fournisseur)
				$sql .= 'AND ref_fournisseur=' . $pdo->quote($ref_id) . '';
			else
				$sql .= 'AND ref_contact=' . $pdo->quote($ref_id) . '';

			//on teste si l'adresse existe déjà
			$resultat = $pdo->query($sql);

			if ($resultat->rowCount() == 0) {
				//insertion des données
				if ($est_fournisseur) {
					$sql = 'INSERT INTO adresse(ref_fournisseur,pays,ville,voie,num_voie,code_postal,num_appartement,telephone_fixe) VALUES(
' . $pdo->quote($ref_id) . ',' . $pdo->quote($pays) . ',' . $pdo->quote($ville) . ',' . $pdo->quote($voie) . ',' . $pdo->quote($num_voie) . ',
' . $pdo->quote($code_postal) . ',' . $pdo->quote($num_appartement) . ',' . $pdo->quote($telephone_fixe) . ')';
				} else {
					$sql = 'INSERT INTO adresse(ref_contact,pays,ville,voie,num_voie,code_postal,num_appartement,telephone_fixe) VALUES(
' . $pdo->quote($ref_id) . ',' . $pdo->quote($pays) . ',' . $pdo->quote($ville) . ',' . $pdo->quote($voie) . ',' . $pdo->quote($num_voie) . ',
' . $pdo->quote($code_postal) . ',' . $pdo->quote($num_appartement) . ',' . $pdo->quote($telephone_fixe) . ')';
				}
				$pdo->query($sql);

				//return new \SoapFault('Server','[AA00011] '.$sql.'.');


			} else {
				return new \SoapFault('Server', '[AA002] Paramètres invalides.');
			}

			$i++;
		}
		return "OK";


	}

	/**
	 * Permet d'avoir les statistiques des ventes par mois.
	 *
	 * @Soap\Method("statsVenteTopVente")
	 * @Soap\Param("nbTop",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function statsVenteTopVenteAction($nbTop)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SVTVM001] Vous n\'avez pas les droits nécessaires.');

		if (!is_int($nbTop)) // Vérif des arguments
			return new \SoapFault('Server', '[SVTV002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		$sql = 'SELECT nom, SUM(
					CASE 
				        WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction/100)*(-1*quantite_mouvement)
				        WHEN type_reduction = \'remise\' THEN (montant_client-reduction)*(-1*quantite_mouvement)
				        ELSE montant_client*(-1*quantite_mouvement)
				    END) AS montant
				FROM (
				SELECT ligne_facture_id,article_id, MAX(prix_id), nom, montant_client, type_reduction, reduction, quantite_mouvement
				        FROM(
				        SELECT facture.date_facture,ligne_facture.id as "ligne_facture_id", 
				               article.id as "article_id", 
				               prix.id as "prix_id", 
				               prix.montant_client,
				               produit.nom, 
				               reduction, 
							   type_reduction,
				               quantite_mouvement
				        FROM facture  
						JOIN ligne_facture ON facture.id = ligne_facture.ref_facture
						JOIN mouvement_stock ON ligne_facture.ref_mvt_stock=mouvement_stock.id
						JOIN article ON mouvement_stock.ref_article=article.id
				        JOIN prix ON prix.ref_article=article.id
				        JOIN produit ON article.ref_produit=produit.id
				        LEFT OUTER JOIN remise ON ligne_facture.ref_remise=remise.id
				        WHERE MONTH(facture.date_facture) = MONTH(NOW()))t
				        GROUP BY article_id,ligne_facture_id) t 
					GROUP BY nom
				    LIMIT ' . (int)$nbTop;

		$resultat = $pdo->query($sql);
		foreach ($resultat as $row) {
			$jour = array('produit' => $row['nom'], 'montant' => $row['montant']);
			array_push($result, $jour);
		}

		return json_encode($result);
	}

	/**
	 * Permet d'avoir les statistiques des ventes par mois.
	 *
	 * @Soap\Method("statsVenteMoyenneParMois")
	 * @Soap\Param("nbMois",phpType="int")
	 * @Soap\Result(phpType = "string")
	 */
	public function statsVenteMoyenneParMoisAction($nbMois)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[SVMVPM001] Vous n\'avez pas les droits nécessaires.');

		if (!is_int($nbMois)) // Vérif des arguments
			return new \SoapFault('Server', '[SVMVPM002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		$sql = 'SELECT SUM(
						CASE 
					        WHEN type_reduction = \'taux\' THEN (montant_client-montant_client*reduction/100)*(-1*quantite_mouvement)
					        WHEN type_reduction = \'remise\' THEN (montant_client-reduction)*(-1*quantite_mouvement)
					        ELSE montant_client*(-1*quantite_mouvement)
					    END) AS montant, DATE_FORMAT(date_facture,\'%d/%m/%y\') AS DateJour
					FROM (SELECT ligne_facture_id,
								 article_id, 
								 MAX(prix_id), 
								 date_facture, 
								 reduction, 
								 type_reduction,
								 quantite_mouvement, 
								 montant_client
					        FROM(
								SELECT facture.date_facture, 
									   ligne_facture.id as "ligne_facture_id", 
					                   article.id as "article_id", 
					                   prix.id as "prix_id",
					                   reduction, 
									   type_reduction,
									   quantite_mouvement, 
									   montant_client
								FROM facture  
								JOIN ligne_facture ON facture.id = ligne_facture.ref_facture
								JOIN mouvement_stock ON ligne_facture.ref_mvt_stock=mouvement_stock.id
								JOIN article ON mouvement_stock.ref_article=article.id
								JOIN prix ON prix.ref_article=article.id
					            LEFT OUTER JOIN remise ON ligne_facture.ref_remise=remise.id
								WHERE MONTH(facture.date_facture) = MONTH(NOW()))ta
								GROUP BY article_id, ligne_facture_id
					        
						) t GROUP BY DAY(date_facture)';

		$resultat = $pdo->query($sql);
		foreach ($resultat as $row) {
			$jour = array('montant' => $row['montant'], 'jour' => $row['DateJour']);
			array_push($result, $jour);
		}

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
	 * @Soap\Result(phpType = "string")
	 */
	public function modifAdresseAction($est_visible, $id_ad, $pays, $ville, $voie, $num_voie, $code_postal, $num_appartement, $telephone_fixe)
	{

		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MA001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($est_visible) || !is_string($pays) || !is_string($ville) || !is_string($voie) || !is_string($num_voie) || !is_string($code_postal)
			|| !is_string($num_appartement) || !is_string($telephone_fixe) || !is_string($id_ad)
		) // Vérif des arguments
			return new \SoapFault('Server', '[MA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

		//Formation de la requête SQL
		$tab_est_visible = json_decode($est_visible);
		$tab_id = json_decode($id_ad);
		$tab_pays = json_decode($pays);
		$tab_ville = json_decode($ville);
		$tab_voie = json_decode($voie);
		$tab_num_voie = json_decode($num_voie);
		$tab_code_postal = json_decode($code_postal);
		$tab_num_appartement = json_decode($num_appartement);
		$tab_telephone_fixe = json_decode($telephone_fixe);

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

			$sql = 'UPDATE adresse SET est_visible=' . $pdo->quote($est_visible) . ',pays=' . $pdo->quote($pays) . ', ville=' . $pdo->quote($ville) . ', voie=' . $pdo->quote($voie) . ',
		num_voie=' . $pdo->quote($num_voie) . ',code_postal=' . $pdo->quote($code_postal) . ',num_appartement=' . $pdo->quote($num_appartement) . ',
		telephone_fixe=' . $pdo->quote($telephone_fixe) . ' WHERE id=' . $pdo->quote($id_ad) . '';

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

		if (!is_string($nom)) // Vérif des arguments
			return new \SoapFault('Server', '[AC002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		//$result = array();

		//test de la date de naissance
		$split = array();
		if (preg_match ("/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/", $date_naissance, $split))
		{
			if(checkdate($split[2],$split[3],$split[1]))
			{
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
		$sql = 'SELECT id, nom, prenom, date_naissance, civilite, email, telephone_portable FROM contact WHERE nom=' . $pdo->quote($nom) . '
		 AND prenom=' . $pdo->quote($prenom) . ' AND date_naissance=' . $pdo->quote($date_naissance) . ' AND civilite=' . $pdo->quote($civilite) . '
		 AND email=' . $pdo->quote($email) . ' AND telephone_portable=' . $pdo->quote($telephone_portable) . ' ';

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

		return new \SoapFault('Server', $sql);

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
			return new \SoapFault('Server', '[GA001] Vous n\'avez pas les droits nécessaires.');


		if (!is_string($nom) || !is_string($prenom) || !is_string($date_naissance)
			|| !is_string($civilite) || !is_string($email) || !is_string($telephone_portable)
			|| !is_string($ok_sms) || !is_string($ok_mail) || !is_string($notes)
			|| !is_int($offset) || !is_int($count)
		)// Vérif des arguments
			return new \SoapFault('Server', '[GA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();



		// Formation de la requete SQL
		$sql = 'SELECT id, nom, prenom, date_naissance, civilite, email, telephone_portable, ok_sms, ok_mail, notes FROM contact ';

		$arguments = array();
		if (!empty($nom) || !empty($prenom) || !empty($date_naissance) || !empty($civilite) || !empty($email) || !empty($telephone_portable) ||
			!empty($ok_sms)
			|| !empty($ok_mail) || !empty($notes)
		) {

			//return new \SoapFault('Server', $ok_mail);

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
				if (preg_match ("/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/", $date_naissance, $split))
				{
					if(checkdate($split[2],$split[3],$split[1]))
					{
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

			$sql .= 'WHERE ';

			$i = 0;
			$taille_avant_fin = count($arguments) - 1;
			while ($i < $taille_avant_fin) {

				$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';

				$i++;
			}
			$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
			$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND est_visible=\'1\'';



		}

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
			return new \SoapFault('Server', '[MF001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($nom) || !is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[MF002] Paramètres invalides.');


		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();

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
		if (preg_match ("/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/", $date_naissance, $split))
		{
			if(checkdate($split[2],$split[3],$split[1]))
			{
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

		$resultat = $pdo->query($sql);
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
		$sql='select ville, count(contact.id) as "nb_personne" from contact
join adresse on adresse.ref_contact=contact.id where ville<>\'\'
group by ville;';

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
		$result = array();


		// Formation de la requete SQL
		$tab_fournisseur_id = array(json_decode($fournisseur_id));
		$tab_article_code = array(json_decode($article_code));
		$tab_quantite_souhaite = array(json_decode($quantite_souhaite));
		$tab_date_commande = array(json_decode($date_commande));



		//return new \SoapFault('Server', $tab_fournisseur_id[0]);

		foreach($tab_article_code as $article_code){

			$sql = 'SELECT * FROM article WHERE code_barre='.$pdo->quote($article_code).';';
			$resultat = $pdo->query($sql);

			if ($resultat->rowCount() == 0) {
				return new \SoapFault('Server', '[ACF003] Article '.$article_code.' invalide.');
			}
		}
		//insertion des données

		$sql = 'INSERT INTO commande_fournisseur(ref_fournisseur, date_commande) VALUES(' . $pdo->quote($tab_fournisseur_id[0]) . ',
		'.$pdo->quote($tab_date_commande[0]).')';

		//return new \SoapFault('Server', $sql);
		$pdo->query($sql);

		$i = 0;
		foreach ($tab_article_code as $article_code) {

			$quantite_souhaite = $tab_quantite_souhaite[$i];
			$sql_f = 'SELECT MAX(id) as "max_id" FROM commande_fournisseur;';
			$sql_a = 'SELECT MAX(id) as "max_id_article" FROM article WHERE code_barre='.$pdo->quote($article_code).';';
			$result_f = $pdo->query($sql_f);

			foreach($pdo->query($sql_a) as $row){
				$id_article = $row['max_id_article'];
			}

			if($result_f->rowCount() == 0)
				$id_commande = 1;
			else{
				foreach($result_f as $row){
					$id_commande = $row['max_id'];
				}
			}
			//insertion des données
				$sql = 'INSERT INTO ligne_commande_fournisseur(ref_commande_fournisseur,ref_article,quantite_souhaite)
VALUES('.$pdo->quote($id_commande).','.$pdo->quote($id_article).','.$pdo->quote($quantite_souhaite).')';

			$pdo->query($sql);
			//return new \SoapFault('Server','[AA00011] '.$sql.'.');


			$i++;
		}

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
			return new \SoapFault('Server', '[GA001] Vous n\'avez pas les droits nécessaires.');


		if ((!is_int($fournisseur_id) && !is_string($fournisseur_id)) || (!is_int($commande_id) && !is_string($commande_id))
			|| !is_int($offset) || !is_int($count) || (!is_int($article_code) && !is_string($article_code) || !is_string($fournisseur_nom))
		)// Vérif des arguments
			return new \SoapFault('Server', '[GA002] Paramètres invalides.');

		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();



		// Formation de la requete SQL
		$sql = 'select fournisseur.nom as "fournisseur_nom", commande_fournisseur.id as "commande_id", date_commande, code_barre, SUM(quantite_souhaite) as "quantite_souhaite",
SUM(quantite_mouvement) AS "quantite_recu" from commande_fournisseur
JOIN ligne_commande_fournisseur ON ligne_commande_fournisseur.ref_commande_fournisseur = commande_fournisseur.id
JOIN article on article.id = ligne_commande_fournisseur.ref_article
JOIN fournisseur ON fournisseur.id = commande_fournisseur.ref_fournisseur
LEFT OUTER JOIN reception on reception.ref_commande_fournisseur = commande_fournisseur.id
LEFT OUTER JOIN ligne_reception ON reception.id=ligne_reception.ref_reception
LEFT OUTER JOIN mouvement_stock ON ligne_reception.ref_mvt_stock = mouvement_stock.id ';

		$arguments = array();
		if (!empty($fournisseur_id) || !empty($commande_id) || !empty($article_code)) {

			if (!empty($fournisseur_id))
				array_push($arguments, array('commande_fournisseur.ref_fournisseur' => $fournisseur_id));
			if (!empty($commande_id))
				array_push($arguments, array('commande.id' => $commande_id));
			if (!empty($article_code))
				array_push($arguments, array('ligne_commande.ref_article' => $article_code));
			if (!empty($fournisseur_nom))
				array_push($arguments, array('fournisseur.nom' => $fournisseur_nom));

			$sql .= 'WHERE ';

			$i = 0;
			$taille_avant_fin = count($arguments) - 1;
			while ($i < $taille_avant_fin) {

				$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
				$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND';

				$i++;
			}
			$val = '%' . $arguments[$i][key($arguments[$i])] . '%';
			$sql .= ' ' . key($arguments[$i]) . ' LIKE ' . $pdo->quote($val) . ' AND est_visible=\'1\'';



		}

		$sql .= 'GROUP BY fournisseur.nom, commande_fournisseur.id, date_commande, code_barre';
		if ($offset != 0) {
			$sql .= ' ORDER BY fournisseur.nom ASC LIMIT ' . (int)$offset;
			if ($count != 0)
				$sql .= ',' . (int)$count;
		}
		else {
			$sql .= ' ORDER BY fournisseur.nom ASC';
		}

		/*
		 * select fournisseur.nom as "fournisseur_nom", commande_fournisseur.id as "commande_id", date_commande, code_barre, SUM(quantite_souhaite) as "quantite_souhaite",
SUM(quantite_mouvement) AS "quantite_recu"
		 */
		//id, pays, ville, voie, num_voie, code_postal, num_appartement, telephone_fixe
		foreach ($pdo->query($sql) as $row) { // Création du tableau de réponse
			$ligne = array('fournisseur_nom' => $row['fournisseur_nom'], 'commande_id' => $row['commande_id'],
				'date_commande' => $row['date_commande'], 'code_barre' => $row['code_barre'],
				'quantite_souhaite'=>$row['quantite_souhaite'],'quantite_recu'=>$row['quantite_recu']);
			array_push($result, $ligne);
		}
		return json_encode($result);
		//return new \SoapFault('Server', $sql);
	}


	/**
	 * @Soap\Method("modifCommandeFournisseur")
	 * @Soap\Param("id",phpType="int")
	 * @Soap\Param("article_code",phpType="string")
	 * @Soap\Param("quantite_souhaite",phpType="string")
	 * @Soap\Result(phpType = "string")
	 */
	public function modifLigneCommandeFournisseurAction($id, $article_code, $quantite_souhaite)
	{
		if (!($this->container->get('user_service')->isOk('ROLE_GERANT'))) // On check les droits
			return new \SoapFault('Server', '[MF001] Vous n\'avez pas les droits nécessaires.');

		if (!is_string($article_code) || !is_int($id)) // Vérif des arguments
			return new \SoapFault('Server', '[MF002] Paramètres invalides.');


		$pdo = $this->container->get('bdd_service')->getPdo(); // On récup PDO depuis le service
		$result = array();


		// Formation de la requete SQL
		$sql = 'UPDATE ligne_commande_fournisseur SET code_barre='.$pdo->quote($article_code).'
		, quantite_souhaite='.$pdo->quote($quantite_souhaite).' WHERE id='.$pdo->quote($id).';';

		$resultat = $pdo->query($sql);
		$pdo->query($sql);

		return "OK";
		//return new \SoapFault('Server', $sql);

	}


}