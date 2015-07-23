<?php

/**
 * Adapteur par défaut de la couche de stockage de Cumulus - utilise une base de
 * données MySQL
 */
class StockageTB implements CumulusInterface {

	/** Config passée par Cumulus.php */
	protected $config;

	/** Base de données PDO */
	protected $db;

	/** Inverseur de critères: si true, les méthodes GET retourneront tous les
		résultats qui NE correspondent PAS aux critères demandés */
	protected $inverseCriteria = false;

	public function __construct($config) {
		// copie de la config
		$this->config = $config;

		// base de données
		$DB = $this->config['adapters']['StockageTB']['db'];
		$dsn = "mysql:host=" . $DB['host'] . ";dbname=" . $DB['dbname'] . ";port=" . $DB['port'];
		$this->db = new PDO($dsn, $DB['username'], $DB['password']);

		// pour ne pas récupérer les valeurs en double (indices numériques + texte)
		$this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	}

	/**
	 * Parcourt un jeu de données et décode le JSON de chaque colonne "meta"
	 * @param type $data
	 */
	protected function decodeMeta(&$data) {
		foreach ($data as &$d) {
			$d['meta'] = json_decode($d['meta'], true);
		}
	}

	/**
	 * Renverse ou non la clause $clause en fonction de $this->inverseCriteria;
	 * utilise un NOT (clause) pour l'inversion - attention, donnera sûrement
	 * des résultats non désirés en cas de colonnes NULL @TODO faire mieux
	 * @param type $clause
	 */
	protected function reverseOrNotClause($clause) {
		if ($this->inverseCriteria === true) {
			return "NOT (" . $clause . ")";
		} else {
			return $clause;
		}
	}

	/**
	 * Si $inverse est true, indique à l'adapteur que les critères de recherche
	 * devront être inversés
	 * @param type $inverse
	 */
	public function setInverseCriteria($inverse) {
		// @TODO filtrer l'entrée ?
		$this->inverseCriteria = $inverse;
	}

	/**
	 * Retourne un fichier à partir de sa clef et son chemin
	 * @param type $path
	 * @param type $key
	 */
	public function getByKey($path, $key) {
		if (empty($key)) {
			return false;
		}

		// clauses
		$clauses = array();
		$clauses[] = "fkey = '$key'";
		if (! empty($path)) {
			$clauses[] = "path = '/$path'";
		}
		$clausesString = implode(" AND ", $clauses);

		//requête
		$clausesString = $this->reverseOrNotClause($clausesString);
		$q = "SELECT * FROM cumulus_files WHERE $clausesString LIMIT 1";
		$r = $this->db->query($q);
		if ($r != false) {
			$data = $r->fetchAll();
			$this->decodeMeta($data);
			if (! empty($data[0])) {
				return $data[0];
			}
		}
		return false;
	}

	/**
	 * Retourne une liste de fichiers dont les noms correspondent à $name; si
	 * $trict est true, compare avec un "=" sinon avec un "LIKE"
	 * @param type $name
	 * @param type $strict
	 */
	public function getByName($name, $strict=false) {
		if (empty($name)) {
			return false;
		}

		// clauses
		$clause = "original_name = '$name'";
		if ($strict === false) {
			$clause = "original_name LIKE '%" . str_replace('*', '%', $name) . "%'";
		}

		//requête
		$clause = $this->reverseOrNotClause($clause);
		$q = "SELECT * FROM cumulus_files WHERE $clause ORDER BY path, original_name, last_modification_date DESC";
		//echo "QUERY : $q\n";
		$r = $this->db->query($q);
		if ($r != false) {
			$data = $r->fetchAll();
			$this->decodeMeta($data);
			return $data;
		}
		return false;
	}

	/**
	 * Retourne une liste de fichiers se trouvant dans le répertoire $path; si
	 * $recursive est true, cherchera dans tous les sous-répertoires
	 * @param type $path
	 * @param type $recursive
	 */
	public function getByPath($path, $recursive=false) {
		if (empty($path)) {
			return false;
		}

		// clauses
		$clause = "path = '$path'";
		if ($recursive === true) {
			$clause = "path LIKE '$path%'";
		}

		//requête
		$clause = $this->reverseOrNotClause($clause);
		$q = "SELECT * FROM cumulus_files WHERE $clause ORDER BY path, original_name, last_modification_date DESC";
		//echo "QUERY : $q\n";
		$r = $this->db->query($q);
		if ($r != false) {
			$data = $r->fetchAll();
			$this->decodeMeta($data);
			return $data;
		}
		return false;
	}

	/**
	 * Retourne une liste de fichiers dont les mots-clefs sont $keywords
	 * (séparés par des virgules ) {} si $mode est "OR", un "OU" sera appliqué
	 * entre les mots-clefs, sinon un "ET"
	 * @param type $keywords
	 * @param type $mode
	 */
	public function getByKeywords($keywords, $mode="AND") {
		if (empty($keywords)) {
			return false;
		}

		// clauses
		$keywords = explode(',', $keywords);
		$clauses = array();
		foreach ($keywords as $kw) {
			$not = false;
			if (substr($kw, 0, 1) == "!") {
				$not = true;
				$kw = substr($kw, 1);
			}
			// astuce pour un like qui ne retourne pas les mots-clefs contenant
			// plus que la chaîne demandée
			$clauses[] = "CONCAT(',', keywords, ',') " . ($not ? "NOT " : "") . "LIKE '%,$kw,%'";
		}
		$operator = " AND ";
		if ($mode === "OR") {
			$operator = " OR ";
		}
		$clausesString = implode($operator, $clauses);

		//requête
		$clausesString = $this->reverseOrNotClause($clausesString);
		$q = "SELECT * FROM cumulus_files WHERE $clausesString ORDER BY path, original_name, last_modification_date DESC";
		//echo "QUERY : $q\n";
		$r = $this->db->query($q);
		if ($r != false) {
			$data = $r->fetchAll();
			$this->decodeMeta($data);
			return $data;
		}
		return false;
	}

	/**
	 * Retourne une liste de fichiers appartenant à l'utilisateur $user
	 * @TODO gérer les droits
	 * @param type $user
	 */
	public function getByUser($user) {
		if (empty($user)) {
			return false;
		}
		// clauses
		$clause = "owner = '$user'";

		//requête
		$clause = $this->reverseOrNotClause($clause);
		$q = "SELECT * FROM cumulus_files WHERE $clause ORDER BY path, original_name, last_modification_date DESC";
		//echo "QUERY : $q\n";
		$r = $this->db->query($q);
		if ($r != false) {
			$data = $r->fetchAll();
			$this->decodeMeta($data);
			return $data;
		}
		return false;
	}

	/**
	 * Retourne une liste de fichiers appartenant au groupe $group
	 * @TODO gérer les droits
	 * @param type $group
	 */
	public function getByGroup($group) {
		if (empty($group)) {
			return false;
		}
		// clauses
		$clause = "fgroup = '$group'";

		//requête
		$clause = $this->reverseOrNotClause($clause);
		$q = "SELECT * FROM cumulus_files WHERE $clause ORDER BY path, original_name, last_modification_date DESC";
		//echo "QUERY : $q\n";
		$r = $this->db->query($q);
		if ($r != false) {
			$data = $r->fetchAll();
			$this->decodeMeta($data);
			return $data;
		}
		return false;
	}

	/**
	 * Retourne une liste de fichiers dont le type MIME est $mimetype
	 * @param type $mimetype
	 */
	public function getByMimetype($mimetype) {
		if (empty($mimetype)) {
			return false;
		}
		// clauses
		$clause = "mimetype = '$mimetype'";

		//requête
		$clause = $this->reverseOrNotClause($clause);
		$q = "SELECT * FROM cumulus_files WHERE $clause ORDER BY path, original_name, last_modification_date DESC";
		//echo "QUERY : $q\n";
		$r = $this->db->query($q);
		if ($r != false) {
			$data = $r->fetchAll();
			$this->decodeMeta($data);
			return $data;
		}
		return false;
	}

	/**
	 * Retourne une liste de fichiers en fonction de leur date de création ou de
	 * modification ($dateColumn) : si $date1 et $date2 sont spécifiées,
	 * renverra les fichiers dont la date se trouve entre les deux; sinon,
	 * comparera à $date1 en fonction de $operator ("=", "<" ou ">")
	 * @param type $date1
	 * @param type $date2
	 * @param type $operator
	 */
	public function getByDate($dateColumn, $date1, $date2, $operator="=") {
		if (empty($date1)) {
			return false;
		}
		if (!in_array($operator, array("=", "<", ">"))) {
			return false;
		}
		if (! in_array($dateColumn, array(self::COLUMN_CREATION_DATE, self::COLUMN_LAST_MODIFICATION_DATE))) {
			return false;
		}

		// clauses
		$clauses = array();
		if (! empty($date2)) {
			$clauses[] = "date($dateColumn) > '$date1'";
			$clauses[] = "date($dateColumn) < '$date2'";
		} else {
			$clauses[] = "date($dateColumn) $operator '$date1'";
		}
		$clausesString = implode(" AND ", $clauses);

		//requête
		$clausesString = $this->reverseOrNotClause($clausesString);
		$q = "SELECT * FROM cumulus_files WHERE $clausesString ORDER BY path, original_name, last_modification_date DESC";
		//echo "QUERY : $q\n";
		$r = $this->db->query($q);
		if ($r != false) {
			$data = $r->fetchAll();
			$this->decodeMeta($data);
			return $data;
		}
		return false;
	}

	/**
	 * Recherche avancée - retourne une liste de fichiers correspondant aux
	 * critères de recherche contenus dans $searchParams (paires de
	 * clefs / valeurs ) {} le critère "mode" peut valoir "OR" (par défaut) ou
	 * "AND"
	 * @param type $searchParams
	 */
	public function search($searchParams=array()) {
		if (empty($searchParams)) {
			return false;
		}
		// clauses @TODO factoriser avec les méthodes de recherche spécifiques
		// (pas si simple)
		$clauses = [];
		foreach ($searchParams as $sp => $val) {
			switch($sp) {
				case "key":
					$clauses[] = "(key = '$val')";
					break;
				case "path":
					$subclause = "(path = '$val')";
					// @TODO vérifier booléen ou chaîne
					if (isset($searchParams['path_recursive']) && ($searchParams['path_recursive'] === 'true')) {
						$subclause = "(path LIKE '$val%')";
					}
					$clauses[] = $subclause;
					break;
				case "name":
					$subclause = "(original_name LIKE '%" . str_replace('*', '%', $val) . "%')";
					// @TODO vérifier booléen ou chaîne
					if (isset($searchParams['name_strict']) && ($searchParams['name_strict'] === 'true')) {
						$subclause = "(original_name = '$val')";
					}
					$clauses[] = $subclause;
					break;
				case "keywords":
					$keywords = explode(',', $val);
					$subClauses = array();
					foreach ($keywords as $kw) {
						$not = false;
						if (substr($kw, 0, 1) == "!") {
							$not = true;
							$kw = substr($kw, 1);
						}
						// astuce pour un like qui ne retourne pas les mots-clefs contenant
						// plus que la chaîne demandée
						$subClauses[] = "CONCAT(',', keywords, ',') " . ($not ? "NOT " : "") . "LIKE '%,$kw,%'";
					}
					$operator = " AND ";
					if (isset($searchParams['keywords_mode']) && ($searchParams['keywords_mode'] == "OR")) {
						$operator = " OR ";
					}
					$clauses[] = '(' . implode($operator, $subClauses) . ')';
					break;
				case "user":
					$clauses[] = "(owner = '$val')";
					break;
				case "group":
					$clauses[] = "(fgroup = '$val')";
					break;
				case "mimetype":
					$clauses[] = "(mimetype = '$val')";
					break;
				case "creation_date":
					$clauses[] = "(date(creation_date) = '$val')";
					break;
				case "min_creation_date":
					$clauses[] = "(date(creation_date) > '$val')";
					break;
				case "max_creation_date":
					$clauses[] = "(date(creation_date) < '$val')";
					break;
				case "last_modif_date":
					$clauses[] = "(date(last_modification_date) = '$val')";
					break;
				case "min_last_modif_date":
					$clauses[] = "(date(last_modification_date) > '$val')";
					break;
				case "max_last_modif_date":
					$clauses[] = "(date(last_modification_date) < '$val')";
					break;
				default:
			}
		}
		$operator = " AND ";
		if (! empty($searchParams['mode']) && ($searchParams['mode'] === "OR")) {
			$operator = " OR ";
		}
		$clausesString = implode($operator, $clauses);
	
		// requête
		$clausesString = $this->reverseOrNotClause($clausesString);
		$q = "SELECT * FROM cumulus_files WHERE $clausesString ORDER BY path, original_name, last_modification_date DESC";
		//echo "QUERY : $q\n";
		$r = $this->db->query($q);
		if ($r != false) {
			$data = $r->fetchAll();
			$this->decodeMeta($data);
			return $data;
		}
		return false;
	}

	/**
	 * Ajoute le fichier $file au stock, dans le chemin $path, avec la clef $key,
	 * les mots-clefs $keywords (séparés par des virgules) et les métadonnées
	 * $meta (portion de JSON libre ) {} si $key est null, une clef sera attribuée
	 * @param type $file
	 * @param type $path
	 * @param type $key
	 * @param type $keywords
	 * @param type $meta
	 */
	public function addFile($file, $path, $key=null, $keywords=null, $meta=null ) {}

	/**
	 * Remplace le contenu (si $file est spécifié) et / ou les métadonnées du
	 * fichier $key situé dans $path
	 * @param type $file
	 * @param type $path
	 * @param type $key
	 * @param type $keywords
	 * @param type $meta
	 */
	public function updateByKey($file, $path, $key, $keywords=null, $meta=null ) {}

	/**
	 * Supprime le fichier $key situé dans $path
	 * @param type $path
	 * @param type $key
	 */
	public function deleteByKey($path, $key ) {}

	/**
	 * Retourne les attributs (métadonnées) du fichier $key situé dans $path,
	 * mais pas le fichier lui-même - ne fait pas de différence ici; c'est le
	 * service qui se débrouille
	 * @param type $path
	 * @param type $key
	 */
	public function getAttributesByKey($path, $key ) {
		return $this->getByKey($path, $key);
	}
}