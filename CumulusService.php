<?php

require 'Cumulus.php';

/**
 * API REST pour le stockage de fichiers Cumulus
 */
class CumulusService {

	/** Bibliothèque Cumulus */
	protected $lib;

	/** Config en JSON */
	protected $config = array();
	public static $CONFIG_PATH = "config/service.json";

	/** Autodocumentation en JSON */
	public static $AUTODOC_PATH = "autodoc.json";

	/** HTTP verb received (GET, POST, PUT, DELETE, OPTIONS) */
	protected $verb;

	/** Ressources (éléments d'URI) */
	protected $resources = array();

	/** Paramètres de requête (GET ou POST) */
	protected $params = array();

	/** Racine du domaine (pour construire des URIs) */
	protected $domainRoot;

	/** URL de base pour parser les éléments (ressources) */
	protected $baseURI;

	public function __construct() {
		// config
		if (file_exists(self::$CONFIG_PATH)) {
			$this->config = json_decode(file_get_contents(self::$CONFIG_PATH), true);
		} else {
			throw new Exception("Le fichier " . self::$CHEMIN_CONFIG . " n'existe pas");
		}

		// lib Cumulus
		$this->lib = new Cumulus();

		// méthode HTTP
		$this->verb = $_SERVER['REQUEST_METHOD'];
		//echo "Method: " . $this->verb . PHP_EOL;

		// config serveur
		$this->domainRoot = $this->config['domain_root'];
		$this->baseURI = $this->config['base_uri'];
		//echo "Domain root: " . $this->domainRoot . PHP_EOL;
		//echo "Base URI: " . $this->baseURI . PHP_EOL;

		// initialisation
		$this->getResources();
		$this->getParams();
		//print_r($this->resources);
		//print_r($this->params);

		$this->init();
	}

	/** Post-constructor adjustments */
	protected function init() {
	}

	/** Reads the request and runs the appropriate method */
	public function run() {
		switch($this->verb) {
			case "GET":
				$this->get();
				break;
			case "POST":
				$this->post();
				break;
			case "PUT":
				$this->put();
				break;
			case "DELETE":
				$this->delete();
				break;
			case "OPTIONS":
				$this->options();
				break;
			default:
				$this->sendError("unrecognized method: $this->verb");
		}
	}

	/**
	 * Envoie un message en JSON indiquant un succès et sort du programme
	 * @param type $json le message
	 * @param type $code par défaut 200 (HTTP OK)
	 */
	protected function sendJson($json, $code=200) {
		header('Content-type: application/json');
		http_response_code($code);
		echo json_encode($json);
		exit;
	}

	/**
	 * Envoie un message en JSON indiquant une erreur et sort du programme
	 * @param type $error la chaîne expliquant la raison de l'erreur
	 * @param type $code par défaut 400 (HTTP Bad Request)
	 */
	protected function sendError($error, $code=400) {
		header('Content-type: application/json');
		http_response_code($code);
		echo json_encode(array("error" => $error));
		exit;
	}

	/**
	 * Renvoie plusieurs résultats $results dans un objet JSON, en ajoutant un
	 * lien de téléchargement
	 * @param type $results
	 * @param type $errorMessage
	 * @param type $errorCode
	 */
	protected function sendMultipleResults($results, $errorMessage="no results", $errorCode=404) {
		if ($results == false) {
			$this->sendError($errorMessage, $errorCode);
		} else {
			// création des liens de téléchargement
			$this->buildLinks($results);
			$this->sendJson(
				array(
					"count" => count($results),
					"results" => $results
				)
			);
		}
	}

	/**
	 * Ajoutant un lien de téléchargement "href" à chaque fichier du jeu de
	 * données
	 * @param type $results
	 */
	protected function buildLinks(&$results) {
		foreach ($results as &$r) {
			$r['href'] = $this->buildLink($r['fkey'], $r['path']);
		}
	}

	/**
	 * Retourne un lien de téléchargement relatif à l'URL de base du service,
	 * pour une clef de fichier et un chemin données
	 */
	protected function buildLink($key, $path="/") {
		if (empty($key)) {
			return false;
		}
		$href = $this->domainRoot . $this->baseURI . $path . "/" . $key;
		return $href;
	}

	/**
	 * Envoie le fichier $file au client, en forçant le téléchargement
	 * @param type $file
	 */
	protected function sendFile($file, $mimetype='application/octet-stream') {
		if (! file_exists($file)) {
			$this->sendError("file does not exist in storage");
		}
		header('Content-Type: ' . $mimetype);
		header('Content-Disposition: attachment');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . filesize($file));
		// envoi du contenu
		readfile($file);
		exit;
	}

	/**
	 * Compare l'URI de la requête à l'URI de base pour extraire les éléments d'URI
	 */
	protected function getResources() {
		$uri = $_SERVER['REQUEST_URI'];
		// découpage de l'URI
		$baseURI = $this->baseURI . "/";
		if ((strlen($uri) > strlen($baseURI)) && (strpos($uri, $baseURI) !== false)) {
			$baseUriLength = strlen($baseURI);
			$posQM = strpos($uri, '?');
			if ($posQM != false) {
				$resourcesString = substr($uri, $baseUriLength, $posQM - $baseUriLength);
			} else {
				$resourcesString = substr($uri, $baseUriLength);
			}
			// décodage des caractères spéciaux
			$resourcesString = urldecode($resourcesString);
			//echo "Ressources: $resourcesString" . PHP_EOL;
			$this->resources = explode("/", $resourcesString);
		}
	}

	/**
	 * Récupère les paramètres GET ou POST de la requête
	 */
	protected function getParams() {
		$this->params = $_REQUEST;
	}

	/**
	 * Recherche le paramètre $name dans $this->params; s'il est défini (même
	 * vide), renvoie sa valeur; s'il n'est pas défini, retourne $default
	 */
	protected function getParam($name, $default=null) {
		if (isset($this->params[$name])) {
			return $this->params[$name];
		} else {
			return $default;
		}
	}

	/**
	 * Obtenir un fichier : plusieurs manières dépendamment de l'URI
	 */
	protected function get() {
		// réponse positive par défaut;
		http_response_code(200);

		// il faut au moins une ressource : clef ou méthode
		if (empty($this->resources[0])) {
			$this->usage();
			return false;
		}

		// Inverseur de critères: si true, les méthodes GET retourneront tous les
		// résultats qui NE correspondent PAS aux critères demandés
		if ($this->getParam('INVERSE') !== null) {
			// @TODO et si l'adapteur ne peut réaliser une telle opération ?
			$this->lib->setInverseCriteria(true);
		}

		$firstResource = $this->resources[0];
		// mode de récupération du/des fichiers
		switch($firstResource) {
			case "by-name":
				$this->getByName();
				break;
			case "by-path":
				$this->getByPath();
				break;
			case "by-keywords":
				$this->getByKeywords();
				break;
			case "by-user":
				$this->getByUser();
				break;
			case "by-groups":
				$this->getByGroups();
				break;
			case "by-date":
				$this->getByDate();
				break;
			case "by-mimetype":
				$this->getByMimetype();
				break;
			case "search":
				$this->search();
				break;
			default:
				$this->getByKey();
		}
	}

	/**
	 * Autodescription du service
	 */
	protected function usage() {
		$rootUri = $this->domainRoot . $this->baseURI . "/";
		$infos = array(
			"error" => "wrong URI"
		);
		// lecture de l'autodoc en JSON et remplacement de l'URI racine
		if (file_exists(self::$AUTODOC_PATH)) {
			$infos = json_decode(file_get_contents(self::$AUTODOC_PATH), true);
			foreach ($infos['uri-patterns'] as &$up) {
				$up[0] = str_replace("__ROOTURI__", $rootUri, $up[0]);
			}
		}
		$this->sendJson($infos);
	}

	/**
	 * GET http://tb.org/cumulus.php/chemin/arbitraire/clef
	 * 
	 * Récupère le fichier clef contenu dans le répertoire /chemin/arbitraire
	 * (déclenche son téléchargement)
	 */
	protected function getByKey() {
		$key = array_pop($this->resources);
		$path = implode('/', $this->resources);

		//echo "getByKey : [$path] [$key]\n";
		$file = $this->lib->getByKey($path, $key);

		if ($file == false) {
			$this->sendError("file not found", 404);
		} else {
			$this->sendFile($file['disk_path'], $file['mimetype']);
		}
	}

	/**
	 * GET http://tb.org/cumulus.php/by-name/compte rendu
	 * GET http://tb.org/cumulus.php/by-name/compte rendu?LIKE (par défaut)
	 * GET http://tb.org/cumulus.php/by-name/compte rendu?STRICT
	 * 
	 * Renvoie une liste de fichiers (les clefs et les attributs) correspondant
	 * au nom ou à la / aux portion(s) de nom fournie(s), quels que soient leurs
	 * emplacements
	 * @TODO paginate, sort and limit
	 */
	protected function getByName() {
		$name = isset($this->resources[1]) ? $this->resources[1] : null;
		$strict = false;
		if ($this->getParam('STRICT') !== null) {
			$strict = true;
		}

		//echo "getByName : [$name]\n";
		//var_dump($strict);
		$files = $this->lib->getByName($name, $strict);

		$this->sendMultipleResults($files);
	}

	/**
	 * GET http://tb.org/cumulus.php/by-path/mon/super/chemin
	 * Renvoie une liste de fichiers (les clefs et les attributs) présents dans
	 * un dossier dont le chemin est /mon/super/chemin
	 * 
	 * GET http://tb.org/cumulus.php/by-path/mon/super/chemin?R
	 * Renvoie une liste de fichiers (les clefs et les attributs) présents dans
	 * un dossier dont le chemin est /mon/super/chemin ou un sous-dossier
	 */
	protected function getByPath() {
		array_shift($this->resources);
		$path = '/' . implode('/', $this->resources);
		$recursive = false;
		if ($this->getParam('R') !== null) {
			$recursive = true;
		}

		//echo "getByPath : [$path]\n";
		//var_dump($recursive);
		$files = $this->lib->getByPath($path, $recursive);

		$this->sendMultipleResults($files);
	}

	/**
	 * GET http://tb.org/cumulus.php/by-keywords/foo
	 * GET http://tb.org/cumulus.php/by-keywords/foo,bar,couscous
	 * GET http://tb.org/cumulus.php/by-keywords/foo,bar,couscous?AND (par défaut)
	 * GET http://tb.org/cumulus.php/by-keywords/foo,bar,couscous?OR
	 * 
	 * Renvoie une liste de fichiers (les clefs et les attributs) correspondant
	 * à un ou plusieurs mots-clefs
	 */
	protected function getByKeywords() {
		$keywords = isset($this->resources[1]) ? $this->resources[1] : null;
		$mode = "AND";
		if ($this->getParam('OR') !== null) {
			$mode = "OR";
		}

		//echo "getByKeywords : [$keywords] [$mode]\n";
		$files = $this->lib->getByKeywords($keywords, $mode);

		$this->sendMultipleResults($files);
	}

	/**
	 * GET http://tb.org/cumulus.php/by-groups/botanique-à-bort-les-orgues
	 * 
	 * Renvoie une liste de fichiers (les clefs et les attributs) appartenant au
	 * groupe "botanique-à-bort-les-orgues"
	 */
	protected function getByGroups() {
		$groups = isset($this->resources[1]) ? $this->resources[1] : null;
		$mode = "AND";
		if ($this->getParam('OR') !== null) {
			$mode = "OR";
		}

		//echo "getByGroups : [$groups] [$mode]\n";
		$files = $this->lib->getByGroups($groups, $mode);

		$this->sendMultipleResults($files);
	}

	/**
	 * GET http://tb.org/cumulus.php/by-user/jean-bernard@tela-botanica.org
	 * 
	 * Renvoie une liste de fichiers (les clefs et les attributs) appartenant à
	 * l'utilisateur jean-bernard@tela-botanica.org
	 */
	protected function getByUser() {
		$user = isset($this->resources[1]) ? $this->resources[1] : null;

		//echo "getByUser : [$user]\n";
		$files = $this->lib->getByUser($user);

		$this->sendMultipleResults($files);
	}

	/**
	 * GET http://tb.org/cumulus.php/by-mimetype/image/png
	 * 
	 * Renvoie une liste de fichiers (les clefs et les attributs) ayant un type MIME "image/png"
	 */
	protected function getByMimetype() {
		array_shift($this->resources);
		// les mimetypes contiennent des "/"
		$mimetype = implode('/', $this->resources);

		// echo "getByMimetype : [$mimetype]\n";
		$files = $this->lib->getByMimetype($mimetype);

		$this->sendMultipleResults($files);
	}

	/**
	 * GET http://tb.org/cumulus.php/by-date/2015-02-04
	 * Renvoie une liste de fichiers (les clefs et les attributs) datant
	 * exactement du 04/02/2015
	 * 
	 * GET http://tb.org/cumulus.php/by-date/2015-02-04?BEFORE
	 * Renvoie une liste de fichiers (les clefs et les attributs) datant d'avant
	 * le 04/02/2015 (exclu)
	 * 
	 * GET http://tb.org/cumulus.php/by-date/2015-02-04?AFTER
	 * Renvoie une liste de fichiers (les clefs et les attributs) datant d'après
	 * le 04/02/2015 (exclu)
	 * 
	 * GET http://tb.org/cumulus.php/by-date/2014-07-13/2015-02-04
	 * Renvoie une liste de fichiers (les clefs et les attributs) datant d'entre
	 * le 13/07/2014 et le 04/02/2015
	 */
	protected function getByDate() {
		// date de création ou de modification ?
		$dateColumn = isset($this->resources[1]) ? $this->resources[1] : null;
		switch ($dateColumn) {
			case "creation":
				$dateColumn = CumulusInterface::COLUMN_CREATION_DATE;
				break;
			case "modification":
				$dateColumn = CumulusInterface::COLUMN_LAST_MODIFICATION_DATE;
				break;
			default:
				//$this->usage();
		}
		$date1 = isset($this->resources[2]) ? $this->resources[2] : null;
		// une ou deux dates fournies ?
		$date2 = null;
		if (! empty($this->resources[3])) {
			$date2 = $this->resources[3];
		}
		// opérateur de comparaison si une seule date fournie
		$operator = "=";
		if ($date2 === null) {
			if ($this->getParam('BEFORE') !== null) {
				$operator = "<";
			} elseif ($this->getParam('AFTER') !== null) {
				$operator = ">";
			}
		}

		//echo "getByDate : [$dateColumn] [$date1] [$date2] [$operator]\n";
		$files = $this->lib->getByDate($dateColumn, $date1, $date2, $operator);

		$this->sendMultipleResults($files);
	}

	/**
	 * GET http://tb.org/cumulus.php/search/foo,bar
	 * Recherche floue parmi les noms et les mots-clefs
	 * 
	 * GET http://tb.org/cumulus.php/search?keywords=foo,bar&user=jean-bernard@tela-botanica.org&date=...
	 * Recherche avancée
	 */
	protected function search() {
		// mode pour les requêtes contenant une ressource (mode simplifié)
		$mode = "AND";
		if ($this->getParam('OR') !== null) {
			$mode = "OR";
		}
		// paramètres de recherche
		$searchParams = array(
			"mode" => $mode
		);
		// URL simplifiée ou non
		if (! empty($this->resources[1])) {
			$searchParams['keywords'] = $this->resources[1];
			$searchParams['name'] = $this->resources[1];
			$searchParams['mode'] = "OR";
		} else {
			$searchParams = $this->params;
		}

		//echo "search :\n";
		//var_dump($searchParams);
		$files = $this->lib->search($searchParams);

		$this->sendMultipleResults($files);
	}

	/**
	 * Écrase ou modifie les attributs d'un fichier existant, et renvoie les
	 * attributs
	 */
	protected function put() {
		$key = array_pop($this->resources);
		$path = implode('/', $this->resources);
		$file = null;
		$keywords = $this->getParam('keywords');
		$meta = $this->getParam('meta');

		if (! empty($_FILES['file'])) {
			// envoi avec multipart/form-data
			$file = $_FILES['file'];
		} // sinon envoi en flux, contenu du fichier dans le corps de la requête

		echo "PUT: [$file] [$path] [$key] [$keywords] [$meta]";

		$this->lib->updateByKey($file, $path, $key, $keywords, $meta);
	}

	/**
	 * Ajoute un fichier et renvoie sa clef et ses attributs
	 */
	protected function post() {
		$path = implode('/', $this->resources);
		$file = null;
		$key = $this->getParam('key');
		$keywords = $this->getParam('keywords');
		$meta = $this->getParam('meta');

		if (! empty($_FILES['file'])) {
			// envoi avec multipart/form-data
			$file = $_FILES['file'];
		} // sinon envoi en flux (contenu du fichier dans le corps de la requête)

		echo "POST: [$file] [$path] [$key] [$keywords] [$meta]";

		$this->lib->addFile($file, $path, $key, $keywords, $meta);
	}

	/**
	 * Supprime un fichier par sa clef, ou par sa clef plus son chemin
	 */
	protected function delete() {
		$key = array_pop($this->resources);
		$path = implode('/', $this->resources);

		echo "delete : [$path] [$key]\n";
		$info = $this->lib->deleteByKey($path, $key);

		if ($info == false) {
			$this->sendError("file not found in storage");
		} else {
			$this->sendJson($info);
		}
	}

	/**
	 * Renvoie les attributs d'un fichier, mais pas le fichier lui-même
	 */
	protected function options() {
		$key = array_pop($this->resources);
		$path = implode('/', $this->resources);

		//echo "options : [$path] [$key]\n";
		$file = $this->lib->getAttributesByKey($path, $key);
		$file['href'] = $this->buildLink($file['fkey'], $file['path']);

		if ($file == false) {
			$this->sendError("file not found", 404);
		} else {
			$this->sendJson($file);
		}
	}
}