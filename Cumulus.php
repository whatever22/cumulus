<?php

/**
 * Bibliothèque pour le stockage de fichiers ("cloud") - API : fournit les
 * méthodes de haut niveau correspondant aux cas d'utilisation
 */
class Cumulus {

	/** Base de données PDO */
	protected $db;

	/** Config en JSON */
	protected $config = array();
	public static $CHEMIN_CONFIG = "config/config.json";

	public function __construct() {
		// config
		if (file_exists(self::$CHEMIN_CONFIG)) {
			$this->config = json_decode(file_get_contents(self::$CHEMIN_CONFIG), true);
		} else {
			throw new Exception("Le fichier " . self::$CHEMIN_CONFIG . " n'existe pas");
		}

		// database
		$DB = $this->config['db'];
		$dsn = "mysql:host=" . $DB['host'] . ";dbname=" . $DB['dbname'] . ";port=" . $DB['port'];
		$this->db = new PDO($dsn, $DB['username'], $DB['password']);

		$this->init();
	}

	/** Post-constructor adjustments */
	protected function init() {
	}

	/** Retreives a file from the stock and sends it */
	public function getByKey($key) {
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
	public function getByName() {
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
	public function getByKeywords() {
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
}