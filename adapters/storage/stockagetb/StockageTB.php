<?php

require 'StockageDisque.php';

/**
 * Adapteur par défaut de la couche de stockage de Cumulus - utilise une base de
 * données MySQL
 * 
 * ATTENTION - on considère que la clef primaire est (fkey,path) et on ne fait
 * pas de vérifications d'unicité dans le code; cependant, la limitation de la
 * taille de clef d'InnoDB à 767 octets implique de fortes limitations sur les
 * tailles de chemin (path) et de nom de fichier (fkey) maximales
 * 
 * @TODO considérer l'utilisation d'une clef (fkey) qui ne soit pas le nom de
 * fichier, et utiliser seulement cette colonne comme clef primaire
 */
class StockageTB implements CumulusInterface {

	public static $PERMISSION_READ = "permission_read";
	public static $PERMISSION_WRITE = "permission_write";

	/** Config passée par Cumulus.php */
	protected $config;

	/** Base de données PDO */
	protected $db;

	/** Lib stockage sur disque */
	protected $diskStorage;

	/** Lib d'authentification - gestion des utilisateurs */
	protected $authAdapter;

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
		// UTF-8
		$this->db->exec("SET CHARACTER SET utf8");

		// lib de stockage sur disque
		$this->diskStorage = new StockageDisque($this->config);

		// pour ne pas récupérer les valeurs en double (indices numériques + texte)
		$this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	}

	/**
	 * Adapteur d'authentification / gestion des droits
	 */
	public function setAuthAdapter($adapter) {
		$this->authAdapter = $adapter;
	}

	/**
	 * Parcourt un jeu de données et décode le JSON de chaque colonne "meta"
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
	 */
	protected function reverseOrNotClause($clause) {
		if ($this->inverseCriteria === true) {
			return "NOT (" . $clause . ")";
		} else {
			return $clause;
		}
	}

	/**
	 * Exécute une requête pour de multiples fichiers en fonction de la clause
	 * $clause, qui sera renversée en fonction de $this->inverseCriteria, et
	 * renvoie une liste de résultats avec les métadonnées décodées
	 * @return boolean
	 */
	protected function queryMultipleFiles($clause) {
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
	 * Utilise PDO::quote mais gère les valeurs NULL
	 */
	protected function quote($value) {
		if ($value === null) {
			return 'NULL';
		} else {
			return $this->db->quote($value);
		}
	}

	/**
	 * Version de implode() qui accepte NULL dans $pieces sans jeter de warning
	 */
	protected function implode($glue, $pieces) {
		if ($pieces === null) {
			return null;
		} else {
			return implode($glue, $pieces);
		}
	}

	/**
	 * Si $inverse est true, indique à l'adapteur que les critères de recherche
	 * devront être inversés
	 */
	public function setInverseCriteria($inverse) {
		// @TODO filtrer l'entrée ?
		$this->inverseCriteria = $inverse;
	}

	/**
	 * Vérifie que l'utilisateur en cours a le droit d'effectuer l'action
	 * qu'il demande; jette une exception si ce n'est pas le cas
	 */
	protected function getRightsCheckingClause() {
		$clause = "";
		
	}

	/**
	 * Vérifie que l'utilisateur en cours a les droits (de lecture ou d'écriture
	 * selon $permToCheck) sur le fichier dont les caractéristiques sont $file
	 */
	protected function checkPermissionsOnFile($file, $permToCheck=null) {
		// que vérifie-t-on : lecture ou écriture ?
		if (! in_array($permToCheck, array(self::$PERMISSION_READ, self::$PERMISSION_WRITE))) {
			$permToCheck = self::$PERMISSION_READ; // par défaut : lecture
		}

		// caractéristiques du fichier
		$perms = $file['permissions'];
		$owner = $file['owner'];
		$groups = explode(',', $file['groups']);

		// caractéristiques de l'utilisateur
		$currentUserId = $this->authAdapter->getUserId();
		$currentUserGroups = $this->authAdapter->getUserGroups();

		// possibilités d'avoir des droits
		$isFileOwner = ($owner == $currentUserId);
		$fileIsPublic = ($perms == null || $owner == null); // NULL or empty
		$userIsInAllowedGroup = (! empty(array_intersect($groups, $currentUserGroups)));
		$fileHasReadRightsForGroups = (strlen($perms) == 2 && in_array(substr($perms, 0, 1), array('r', 'w')));
		$fileHasReadRightsForOthers = (strlen($perms) == 2 && in_array(substr($perms, 1, 1), array('r', 'w')));
		$fileHasWriteRightsForGroups = (strlen($perms) == 2 && substr($perms, 0, 1) ==  'w');
		$fileHasWriteRightsForOthers = (strlen($perms) == 2 && substr($perms, 1, 1) == 'w');

		// qu'est-ce qui fait qu'on n'a pas les droits ?
		$hasNoRights = (
			// vous n'êtes pas le propriétaire ET
			(! $isFileOwner) &&
			// le fichier n'est pas public
			(! $fileIsPublic)
		);
		// si on doit vérifier la lecture
		if ($permToCheck == self::$PERMISSION_READ) {
			$hasNoRights = $hasNoRights &&
			// le fichier n'est pas lisible par les groupes (ou sinon vous
			// n'êtes pas dans un des bons groupes) ET
			(! $fileHasReadRightsForGroups || ! $userIsInAllowedGroup) &&
			// le fichier n'est pas lisible par les "autres"
			(! $fileHasReadRightsForOthers);
		}
		// si on doit vérifier l'écriture
		else if ($permToCheck == self::$PERMISSION_WRITE) {
			$hasNoRights = $hasNoRights &&
			// le fichier n'est pas écrivable par les groupes (ou sinon vous
			// n'êtes pas dans un des bons groupes) ET
			(! $fileHasWriteRightsForGroups || ! $userIsInAllowedGroup) &&
			// le fichier n'est pas écrivable par les "autres"
			(! $fileHasWriteRightsForOthers);
		}

		if ($hasNoRights) {
			// vous n'avez pas les droits
			throw new Exception("storage: insufficent persmissions");
		} // sinon tout va bien
	}

	/**
	 * Retourne un fichier à partir de sa clef et son chemin
	 */
	public function getByKey($path, $key) {
		if (empty($key)) {
			throw new Exception('storage: no file key specified');
		}

		// clauses
		$clauses = array();
		$clauses[] = "fkey = '$key'";
		if (! empty($path)) {
			$clauses[] = "path = '$path'";
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
				// vérification des droits
				$this->checkPermissionsOnFile($data[0]);
				return $data[0];
			}
		}
		return false;
	}

	/**
	 * Retourne une liste de fichiers dont les noms correspondent à $name; si
	 * $trict est true, compare avec un "=" sinon avec un "LIKE"
	 */
	public function getByName($name, $strict=false) {
		if (empty($name)) {
			throw new Exception('storage: no name specified');
		}

		// clauses
		$clause = "original_name = '$name'";
		if ($strict === false) {
			$clause = "original_name LIKE '%" . str_replace('*', '%', $name) . "%'";
		}

		return $this->queryMultipleFiles($clause);
	}

	/**
	 * Retourne une liste de fichiers se trouvant dans le répertoire $path; si
	 * $recursive est true, cherchera dans tous les sous-répertoires
	 */
	public function getByPath($path, $recursive=false) {
		if (empty($path)) {
			throw new Exception('storage: no path specified');
		}

		// clauses
		$clause = "path = '$path'";
		if ($recursive === true) {
			$clause = "path LIKE '$path%'";
		}

		return $this->queryMultipleFiles($clause);
	}

	/**
	 * Retourne une liste de fichiers dont les mots-clefs sont $keywords
	 * (séparés par des virgules ); si $mode est "OR", un "OU" sera appliqué
	 * entre les mots-clefs, sinon un "ET"; si un mot-clef est préfixé par "!",
	 * on cherchera les fichiers n'ayant pas ce mot-clef
	 */
	public function getByKeywords($keywords, $mode="AND") {
		if (empty($keywords)) {
			throw new Exception('storage: no keyword specified');
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

		return $this->queryMultipleFiles($clausesString);
	}

	/**
	 * Retourne une liste de fichiers appartenant aux groupes $groups
	 * (séparés par des virgules ); si $mode est "OR", un "OU" sera appliqué
	 * entre les groupes, sinon un "ET"; si un groupe est préfixé par "!", on
	 * cherchera les fichiers n'appartenant pas à ce groupe
	 * @TODO gérer les droits
	 */
	public function getByGroups($groups, $mode="AND") {
		if (empty($groups)) {
			throw new Exception('storage: no group specified');
		}

		// clauses
		$groups = explode(',', $groups);
		$clauses = array();
		foreach ($groups as $g) {
			$not = false;
			if (substr($g, 0, 1) == "!") {
				$not = true;
				$g = substr($g, 1);
			}
			// astuce pour un like qui ne retourne pas les groupes contenant
			// plus que la chaîne demandée
			$clauses[] = "CONCAT(',', groups, ',') " . ($not ? "NOT " : "") . "LIKE '%,$g,%'";
		}
		$operator = " AND ";
		if ($mode === "OR") {
			$operator = " OR ";
		}
		$clausesString = implode($operator, $clauses);

		return $this->queryMultipleFiles($clausesString);
	}

	/**
	 * Retourne une liste de fichiers appartenant à l'utilisateur $user
	 * @TODO gérer les droits
	 */
	public function getByUser($user) {
		if (empty($user)) {
			throw new Exception('storage: no user specified');
		}
		// clauses
		$clause = "owner = '$user'";

		return $this->queryMultipleFiles($clause);
	}

	/**
	 * Retourne une liste de fichiers dont le type MIME est $mimetype
	 */
	public function getByMimetype($mimetype) {
		if (empty($mimetype)) {
			throw new Exception('storage: no mimetype specified');
		}
		// clauses
		$clause = "mimetype = '$mimetype'";

		return $this->queryMultipleFiles($clause);
	}

	/**
	 * Retourne une liste de fichiers dont la licence est $license
	 */
	public function getByLicense($license) {
		if (empty($license)) {
			throw new Exception('storage: no license specified');
		}
		// clauses
		$clause = "license = '$license'";

		return $this->queryMultipleFiles($clause);
	}

	/**
	 * Retourne une liste de fichiers en fonction de leur date de création ou de
	 * modification ($dateColumn) : si $date1 et $date2 sont spécifiées,
	 * renverra les fichiers dont la date se trouve entre les deux; sinon,
	 * comparera à $date1 en fonction de $operator ("=", "<" ou ">")
	 */
	public function getByDate($dateColumn, $date1, $date2, $operator="=") {
		if (empty($date1)) {
			throw new Exception('storage: no date specified');
		}
		if (!in_array($operator, array("=", "<", ">"))) {
			throw new Exception('storage: operator must be < or > or =');
		}
		if (! in_array($dateColumn, array(self::COLUMN_CREATION_DATE, self::COLUMN_LAST_MODIFICATION_DATE))) {
			throw new Exception('storage: column must be ' . self::COLUMN_CREATION_DATE . ' or ' . self::COLUMN_LAST_MODIFICATION_DATE);
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

		return $this->queryMultipleFiles($clausesString);
	}

	/**
	 * Recherche avancée - retourne une liste de fichiers correspondant aux
	 * critères de recherche contenus dans $searchParams (paires de
	 * clefs / valeurs ) {} le critère "mode" peut valoir "OR" (par défaut) ou
	 * "AND"
	 */
	public function search($searchParams=array()) {
		if (empty($searchParams)) {
			throw new Exception('storage: no search parameters specified');
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
				case "groups":
					$groups = explode(',', $val);
					$subClauses = array();
					foreach ($groups as $g) {
						$not = false;
						if (substr($g, 0, 1) == "!") {
							$not = true;
							$g = substr($g, 1);
						}
						// astuce pour un like qui ne retourne pas les groupes contenant
						// plus que la chaîne demandée
						$subClauses[] = "CONCAT(',', groups, ',') " . ($not ? "NOT " : "") . "LIKE '%,$g,%'";
					}
					$operator = " AND ";
					if (isset($searchParams['groups_mode']) && ($searchParams['groups_mode'] == "OR")) {
						$operator = " OR ";
					}
					$clauses[] = '(' . implode($operator, $subClauses) . ')';
					break;
				case "user":
					$clauses[] = "(owner = '$val')";
					break;
				case "mimetype":
					$clauses[] = "(mimetype = '$val')";
					break;
				case "license":
					$clauses[] = "(license = '$val')";
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
	
		return $this->queryMultipleFiles($clausesString);
	}

	/**
	 * Ajoute le fichier $file au stock, dans le chemin $path, avec la clef $key,
	 * les mots-clefs $keywords (séparés par des virgules), les groupes $groupes
	 * (séparés par des virgules), les permissions $permissions, la licence
	 * $license et les métadonnées $meta (portion de JSON libre). Si le fichier
	 * existe déjà, il sera remplacé
	 */
	public function addOrUpdateFile($file, $path, $key, $keywords=null, $groups=null, $permissions=null, $license=null, $meta=null) {
		// cas d'erreurs
		if (empty($file)) {
			throw new Exception('storage: no file specified');
		} else {
			if (! isset($file['size']) || $file['size'] == 0) {
				throw new Exception('storage: file is empty');
			}
		}
		if ($key == null) {
			throw new Exception('storage: no key specified');
		}
		// écriture du fichier temporaire dans le fichier de destination, si ce
		// n'est pas une référence sur URL
		$storageInfo = false;
		if (isset($file['tmp_name'])) { // fichier
			$storageInfo = $this->diskStorage->stockerFichier($file, $path, $key);
		} else if (isset($file['url'])) { // référence
			$storageInfo = array(
				'disk_path' => $file['url'],
				'mimetype' => null
			);
		} else {
			throw new Exception('invalid storageInfo');
		}
		// si ça s'est bien passé, insertion dans la BD
		if ($storageInfo != false) {
			$existingFile = $this->getByKey($path, $key);
			// si la référence du fichier existe déjà dans la bdd
			if ($existingFile == false) {
				// insertion
				$insertInfo = $this->insertFileReference($storageInfo, $path, $key, $keywords, $groups, $permissions, $license, $meta);
			} else {
				// mise à jour
				$insertInfo = $this->updateFileReference($storageInfo, $path, $key, $keywords, $groups, $permissions, $license, $meta);
				// si on avait un fichier avant et qu'on le remplace par une
				// référence, on détruit le fichier pour libérer de l'espace
				if (isset($file['url'])) { // référence
					$this->diskStorage->supprimerFichier($existingFile['storage_path']);
				}
			}
			// si l'insertion / màj s'est bien passée
			if ($insertInfo != false) {
				// re-lecture de toutes les infos (mode fainéant)
				$info = $this->getAttributesByKey($path, $key);
				return $info;
			} else {
				// sinon on détruit le fichier, si ce n'est pas une référence
				if (isset($file['tmp_name'])) {
					$this->diskStorage->supprimerFichier($storageInfo['disk_path']);
				}
			}
		}
		return false;
	}

	/**
	 * Insère une référence de fichier dans la base de données, en fonction des
	 * informations retournées par la couche de stockage sur disque
	 */
	protected function insertFileReference($storageInfo, $path, $key, $keywords=null, $groups=null, $permissions=null, $license=null, $meta=null) {
		// protection des entrées
		$key = $this->quote($key);
		$path = $this->quote($path);
		$keywords = $this->quote($this->implode(',', $keywords));
		$groups = $this->quote($this->implode(',', $groups));
		$permissions = $this->quote($permissions);
		$license = $this->quote($license);
		$meta = $this->quote($meta == null ? null : json_encode($meta));
		$diskPath = $this->quote($storageInfo['disk_path']);
		$mimetype = $this->quote($storageInfo['mimetype']);

		// requete
		$q = "INSERT INTO cumulus_files VALUES ($key, $key, $path"
			. ", $diskPath, $mimetype, NULL, $groups, $permissions"
			. ", $keywords, $license, $meta, DEFAULT, DEFAULT)";
		//echo "QUERY : $q\n";

		$r = $this->db->exec($q);
		// 1 ligne doit être affectée
		return ($r == 1);
	}

	/**
	 * Insère une référence de fichier dans la base de données, en fonction des
	 * informations retournées par la couche de stockage sur disque
	 */
	protected function updateFileReference($storageInfo, $path, $key, $keywords=null, $groups=null, $permissions=null, $license=null, $meta=null) {
		// protection des entrées
		$key = $this->quote($key);
		$path = $this->quote($path);

		// ...et construction de la clause SET
		$setClauses = array();
		if ($keywords !== null) {
			$setClauses[] = 'keywords=' . $this->quote(implode(',', $keywords));
		}
		if ($groups !== null) {
			$setClauses[] = 'groups=' . $this->quote(implode(',', $groups));
		}
		if ($permissions !== null) {
			$setClauses[] = 'permissions=' . $this->quote($permissions);
		}
		if ($license !== null) {
			$setClauses[] = 'license=' . $this->quote($license);
		}
		if ($meta !== null) {
			$setClauses[] = 'meta=' . $this->quote($meta == null ? null : json_encode($meta));
		}
		if (! empty($storageInfo)) {
			$setClauses[] = 'storage_path=' . $this->quote($storageInfo['disk_path']);
			$setClauses[] = 'mimetype=' . $this->quote($storageInfo['mimetype']);
		}
		$setClauses[] = 'last_modification_date=NOW()';

		// agglomération de la clause SET
		$setClausesString = implode(', ', $setClauses);
		if ($setClausesString != '') {
			// requete
			$q = "UPDATE cumulus_files SET $setClausesString"
				. " WHERE fkey=$key AND path=$path";
			//echo "QUERY : $q\n";

			$r = $this->db->exec($q);
			// 1 ligne doit être affectée
			return ($r == 1);
		}
		return false;
	}

	/**
	 * Met à jour les métadonnées du fichier identifié par $key / $path
	 */
	public function updateByKey($path, $key, $keywords=null, $groups=null, $permissions=null, $license=null, $meta=null) {
		// cas d'erreurs
		if ($key == null) {
			throw new Exception('storage: no key specified');
		}
		// mise à jour
		$existingFile = $this->getByKey($path, $key);
		// si la référence du fichier existe déjà dans la bdd
		if ($existingFile == false) {
			throw new Exception('storage: file entry not found');
		} else {
			// mise à jour
			$updateInfo = $this->updateFileReference(null, $path, $key, $keywords, $groups, $permissions, $license, $meta);
		}
		// si l'insertion / màj s'est bien passée
		if ($updateInfo != false) {
			// re-lecture de toutes les infos (mode fainéant)
			$info = $this->getAttributesByKey($path, $key);
			return $info;
		} else {
			throw new Exception('storage: update failed');
		}
		return false;
	}

	/**
	 * Supprime le fichier $key situé dans $path; si $keepFile est true, ne
	 * supprime que la référence mais conserve le fichier dans le stockage
	 */
	public function deleteByKey($path, $key, $keepFile=false) {
		$fileInfo = $this->getByKey($path, $key);
		// si le fichier existe dans la base de données
		if ($fileInfo != false) {
			// suppression de l'entrée dans la base de données
			$deletedFromDb = $this->deleteFileReference($path, $key);
			if ($deletedFromDb == false) {
				throw new Exception('storage: cannot delete file entry from database');
			}
			if ($keepFile == false) {
				// destruction du fichier
				$diskPath = $fileInfo['storage_path'];
				$this->diskStorage->supprimerFichier($diskPath);
			}
			return array(
				"deleted" => true,
				"fkey" => $fileInfo['fkey'],
				"path" => $fileInfo['path']
			);
		}
		return false;
	}

	/**
	 * Supprime une entrée de fichier dans la base de données
	 */
	protected function deleteFileReference($path, $key) {
		if (empty($key)) {
			throw new Exception('storage: no key specified');
		}

		// clauses
		$clauses = array();
		$clauses[] = "fkey = '$key'";
		if (! empty($path)) {
			$clauses[] = "path = '$path'";
		}
		$clausesString = implode(" AND ", $clauses);

		//requête
		$q = "DELETE FROM cumulus_files WHERE $clausesString";
		$r = $this->db->exec($q);

		return $r;
	}

	/**
	 * Retourne les attributs (métadonnées) du fichier $key situé dans $path,
	 * mais pas le fichier lui-même - ne fait pas de différence ici; c'est le
	 * service qui se débrouille
	 */
	public function getAttributesByKey($path, $key ) {
		return $this->getByKey($path, $key);
	}
}