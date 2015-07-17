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
	public static $CHEMIN_CONFIG = "config/service.json";

	/** HTTP verb received (GET, POST, PUT, DELETE, OPTIONS) */
	protected $verb;

	/** Ressources (éléments d'URI) */
	protected $resources = array();

	/** Paramètres reçus */
	protected $params = array();

	/** Clef reçue */
	protected $key;

	/** URL de base pour parser les éléments (ressources) */
	protected $baseURI;

	public function __construct() {
		// config
		if (file_exists(self::$CHEMIN_CONFIG)) {
			$this->config = json_decode(file_get_contents(self::$CHEMIN_CONFIG), true);
		} else {
			throw new Exception("Le fichier " . self::$CHEMIN_CONFIG . " n'existe pas");
		}

		// lib Cumulus
		$this->lib = new Cumulus();

		// méthode HTTP
		$this->verb = $_SERVER['REQUEST_METHOD'];
		//echo "Method: " . $this->verb . PHP_EOL;

		// @TODO read from config
		$this->baseURI = $this->config['base_uri'];
		echo "Base URI: " . $this->baseURI . PHP_EOL;

		$this->getResources();
		$this->getParams();
		print_r($this->resources);
		print_r($this->params);

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
				http_response_code(500);
				echo "unrecognized method: $this->verb" . PHP_EOL;
		}
	}

	/** Compare l'URI de la requête à l'URI de base pour extraire les éléments d'URI */
	protected function getResources() {
		$uri = $_SERVER['REQUEST_URI'];
		//echo "URI: " . $uri . PHP_EOL;
		if ((strlen($uri) > strlen($this->baseURI)) && (strpos($uri, $this->baseURI) !== false)) {
			//echo "URI plus!" . PHP_EOL;
			$baseUriLength = strlen($this->baseURI);
			$resources = substr($uri, $baseUriLength, strpos($uri, '?') - $baseUriLength);
			//echo "Ressources: $resources" . PHP_EOL;
			$this->resources = explode("/", $resources);
		}
	}

	/** Récupère les paramètres GET ou POST de la requête */
	protected function getParams() {
		$this->params = $_REQUEST;
	}

	/**
	 * Detects which way the user wants to retreive file(s)
	 */
	protected function get() {
		if (empty($this->resources[0])) {
			http_response_code(404);
			return false;
		}
		$res1 = $this->resources[0];
		echo "Get param 1: $res1" . PHP_EOL;

		// is it a raw GET or a search ?
		switch($res1) {
			case "by-keyword":
				$this->getByKeyword();
				break;
			case "by-name":
				$this->getByName();
				break;
			case "search":
				$this->search();
				break;
			default:
				$this->getByKey($res1);
		}
	}

	/** Retreives a file from the stock and sends it */
	protected function getByKey($key) {
		$q = "SELECT * FROM stor WHERE k='$key'";
		// @TODO access rights
		// ...
		$result = $this->db->query($q)->fetchAll(PDO::FETCH_ASSOC);
		if (empty($result)) {
			echo "key not found";
			http_response_code(404);
			return false;
		}
		echo json_encode($result);
		// @TODO send file contents to stdout
	}

	/**
	 * Searches (public) files by name and sends a list
	 * @TODO paginate, sort and limit
	 */
	protected function getByName() {
		if (empty($this->params[1])) {
			http_response_code(404);
			echo "no name specified";
			return false;
		}
		$name = $this->params[1];

		$q = "SELECT * FROM stor WHERE name LIKE '%$name%'";
		// @TODO access rights
		// ...
		$result = $this->db->query($q)->fetchAll(PDO::FETCH_ASSOC);
		if (empty($result)) {
			echo "no results";
			http_response_code(404);
			return false;
		}
		echo json_encode($result);
		// @TODO generate proper list with links
	}

	/**
	 * GET http://a.org/stor.php/by-keywords/foo
	 * GET http://a.org/stor.php/by-keywords/foo,bar,couscous
 	 * GET http://a.org/stor.php/by-keywords/foo,bar,couscous/AND (default)
 	 * GET http://a.org/stor.php/by-keywords/foo,bar,couscous/OR
 	 *
	 * Searches (public) files by keywords and sends a list
	 * @TODO paginate, sort and limit
	 */
	protected function getByKeywords() {
		// keywords
		if (empty($this->params[1])) {
			http_response_code(404);
			echo "no keyword specified";
			return false;
		}
		$keywords = $this->params[1];
		$keywords = explode(",", $keywords);
		// clause mode (OR or AND)
		$mode = "AND";
		if (! empty($this->params[1])) {
			http_response_code(404);
			echo "no keyword specified";
			return false;
		}
		$clause = implode(" OR ");

		$q = "SELECT * FROM stor WHERE name LIKE '%$name%'";
		// @TODO access rights
		// ...
		$result = $this->db->query($q)->fetchAll(PDO::FETCH_ASSOC);
		if (empty($result)) {
			echo "no results";
			http_response_code(404);
			return false;
		}
		echo json_encode($result);
		// @TODO generate proper list with links
	}

	/** Adds a file to the stock and sends the random generated key associated to it */
	protected function put() {
		//$key = sha2(microtime()); // @TODO test reliability
		$key = md5(microtime());
		echo $key;
	}

	/** Replaces an existing file with a new one, using the key */
	protected function post() {
		if (empty($this->params[0])) {
			http_response_code(403);
			echo "missing key" . PHP_EOL;
			return false;
		}
		$key = $this->params[0];
	}
}