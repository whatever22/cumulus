<?php

/* 
 * Base class for REST services
 * @author mathias@tela-botanica.org
 * @date 08/2015
 */
class BaseService {

	/** Config en JSON */
	protected $config = array();
	public static $CONFIG_PATH = "config/service.json";

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
			throw new Exception("file " . self::$CHEMIN_CONFIG . " doesn't exist");
		}

		// méthode HTTP
		$this->verb = $_SERVER['REQUEST_METHOD'];

		// config serveur
		$this->domainRoot = $this->config['domain_root'];
		$this->baseURI = $this->config['base_uri'];

		// initialisation
		$this->getResources();
		$this->getParams();

		$this->init();
	}

	/** Post-constructor adjustments */
	protected function init() {
	}

	/**
	 * Reads the request and runs the appropriate method; catches library
	 * exceptions
	 */
	public function run() {
		try {
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
		} catch(Exception $e) {
			// récupère les exceptions des lib et les transforme en erreur 500
			$this->sendError($e->getMessage(), 500);
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
	 * vide), renvoie sa valeur; s'il n'est pas défini, retourne $default; si
	 * $collection est un tableau non vide, les paramètres seront cherchés dans
	 * celui-ci plutôt que dans $this->params (mode 2 en 1 cracra)
	 */
	protected function getParam($name, $default=null, $collection=null) {
		$arrayToSearch = $this->params;
		if (is_array($collection) && !empty($collection)) {
			$arrayToSearch = $collection;
		}
		if (isset($arrayToSearch[$name])) {
			return $arrayToSearch[$name];
		} else {
			return $default;
		}
	}

	/**
	 * Lit et retourne le contenu du corps de la requête
	 */
	protected function readRequestBody() {
		// @TODO attention à la conso mémoire, mais peut-on faire autrement pour
		// extraire seulement le paramètre "file" et l'écrire dans un fichier
		// temporaire ?
		$contents = file_get_contents('php://input');
		return $contents;
	}
}
