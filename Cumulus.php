<?php

require 'CumulusInterface.php';

/**
 * Bibliothèque pour le stockage de fichiers ("cloud") - API : fournit les
 * méthodes de haut niveau correspondant aux cas d'utilisation
 */
class Cumulus implements CumulusInterface {

	/** Base de données PDO */
	protected $db;

	/** Config en JSON */
	protected $config = array();
	public static $CHEMIN_CONFIG = "config/config.json";

	/** Implémentation de la lib par un adapteur */
	protected $adapter;

	public function __construct() {
		// config
		if (file_exists(self::$CHEMIN_CONFIG)) {
			$this->config = json_decode(file_get_contents(self::$CHEMIN_CONFIG), true);
		} else {
			throw new Exception("Le fichier " . self::$CHEMIN_CONFIG . " n'existe pas");
		}

		// base de données
		$DB = $this->config['db'];
		$dsn = "mysql:host=" . $DB['host'] . ";dbname=" . $DB['dbname'] . ";port=" . $DB['port'];
		$this->db = new PDO($dsn, $DB['username'], $DB['password']);

		// adapteur
		$adapterName = $this->config['adapter'];
		$adapterPath = 'adapters/' . $adapterName . '.php';
		if (strpos($adapterName, "..") != false || $adapterName == '' || ! file_exists($adapterPath)) {
			throw new Exception ("L'adapteur " . $adapterPath . " n'existe pas");
		}
		require $adapterPath;
		$this->adapter = new $adapterName();
	}

	public function getByKey($key) {
		return $this->adapter->getByKey($key);
	}

	public function getByName() {
	}

	public function getByKeywords() {
	}
}