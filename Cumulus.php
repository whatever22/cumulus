<?php

require_once 'CumulusInterface.php';
require_once 'adapters/auth/AuthAdapter.php';
// composer
require_once 'vendor/autoload.php';

/**
 * Bibliothèque pour le stockage de fichiers ("cloud") - API : fournit les
 * méthodes de haut niveau correspondant aux cas d'utilisation, et transmets
 * les appels à l'adapteur choisi dans la config
 */
class Cumulus implements CumulusInterface {

	/** Config en JSON */
	protected $config = array();
	public static $CHEMIN_CONFIG = "config/config.json";


	/** Implémentation de la lib de stockage par un adapteur */
	protected $storageAdapter;

	public function __construct() {
		// config
		if (file_exists(self::$CHEMIN_CONFIG)) {
			$this->config = json_decode(file_get_contents(self::$CHEMIN_CONFIG), true);
		} else {
			throw new Exception("file " . self::$CHEMIN_CONFIG . " doesn't exist");
		}

		// adapteur de stockage
		$storageAdapterName = $this->config['storageAdapter'];
		$storageAdapterDir = strtolower($storageAdapterName);
		$storageAdapterPath = 'adapters/storage/' . $storageAdapterDir . '/' . $storageAdapterName . '.php';
		if (strpos($storageAdapterName, "..") != false || $storageAdapterName == '' || ! file_exists($storageAdapterPath)) {
			throw new Exception ("storage adapter " . $storageAdapterPath . " doesn't exist");
		}
		require $storageAdapterPath;
		// on passe la config à l'adapteur - à lui de stocker ses paramètres
		// dans un endroit correct (adapters.nomdeladapteur par exemple)
		$this->storageAdapter = new $storageAdapterName($this->config);

		// adapteur d'authentification / gestion des droits
		$authAdapter = new AuthAdapter; // adapteur fantoche par défaut (aucune gestion des droits, tout le monde est admin)
		// gestion des droits facultative
		if (! empty($this->config['authAdapter'])) {
			$authAdapterName = $this->config['authAdapter'];
			$authAdapterDir = strtolower($authAdapterName);
			$authAdapterPath = 'adapters/auth/' . $authAdapterDir . '/' . $authAdapterName . '.php';
			if (strpos($authAdapterName, "..") != false || $authAdapterName == '' || ! file_exists($authAdapterPath)) {
				throw new Exception ("auth adapter " . $authAdapterPath . " doesn't exist");
			}
			require $authAdapterPath;
			// on passe la section de la config relative à l'adapteur (voir AuthTB)
			$authAdapter = new $authAdapterName($this, $this->config['adapters'][$authAdapterName]);
		}
		// on passe l'adapteur d'authentification à l'adapteur de stockage
		$this->setAuthAdapter($authAdapter);
	}

	/**
	 * Adapteur d'authentification / gestion des droits (doit être facultatif)
	 */
	public function setAuthAdapter($adapter) {
		$this->storageAdapter->setAuthAdapter($adapter);
	}


	public function createNewFolder($folderName, $path)  {
		return $this->storageAdapter->createNewFolder($folderName, $path);
	}


	/**
	 * Retourne le chemin racine du stockage de fichiers
	 */
	public function getStoragePath() {
		return $this->storageAdapter->getStoragePath();
	}

	/**
	 * Si $inverse est true, indique à l'adapteur que les critères de recherche
	 * devront être inversés
	 */
	public function setInverseCriteria($inverse) {
		$this->storageAdapter->setInverseCriteria($inverse);
	}

	/**
	 * Calcule une clef de fichier à partir du chemin et du nom
	 */
	public function computeKey($path, $fileName) {
		return $this->storageAdapter->computeKey($path, $fileName);
	}

	/**
	 * Retourne true si $string est une clef
	 * @WARNING difficile à rendre déterministe !
	 * @TODO chercher dans la BDD si cette clef existe; ne répond pas vraiment
	 *		à la même question mais ça peut être utile
	 */
	public function isKey($string) {
		return $this->storageAdapter->isKey($string);
	}

	/**
	 * Retourne true si $string représente un dossier, c'est à dire un chemin
	 * contenant au moins un fichier
	 */
	public function isFolder($string) {
		return $this->storageAdapter->isFolder($string);
	}

	/**
	 * Retourne un fichier à partir de sa clef
	 */
	public function getByKey($key) {
		return $this->storageAdapter->getByKey($key);
	}

	/**
	 * Retourne une liste de dossiers se trouvant sous $path; si $recursive est
	 * true, renvoie aussi leurs sous-dossiers
	 */
	public function getFolders($path, $recursive=false) {
		return $this->storageAdapter->getFolders($path, $recursive);
	}

	/**
	 * Retourne une liste des fichiers et dossiers se trouvant sous $path; si
	 * $recursive est true, renvoie aussi les sous-dossiers et les fichiers
	 * qu'ils contiennent
	 */
	public function getFolderContents($path, $recursive=false) {
		return $this->storageAdapter->getFolderContents($path, $recursive);
	}

	/**
	 * Retourne une liste de fichiers dont les noms correspondent à $name; si
	 * $trict est true, compare avec un "=" sinon avec un "LIKE"
	 */
	public function getByName($name, $strict=false) {
		return $this->storageAdapter->getByName($name, $strict);
	}

	/**
	 * Retourne une liste de fichiers se trouvant dans le répertoire $path; si
	 * $recursive est true, cherchera dans tous les sous-répertoires; si
	 * $includeFolders est true, incluera les dossiers présents sous le chemin
	 */
	public function getByPath($path, $recursive=false, $includeFolders=false) {
		return $this->storageAdapter->getByPath($path, $recursive, $includeFolders);
	}

	/**
	 * Retourne une liste de fichiers dont les mots-clefs sont $keywords
	 * (séparés par des virgules ); si $mode est "OR", un "OU" sera appliqué
	 * entre les mots-clefs, sinon un "ET"; si un mot-clef est préfixé par "!",
	 * on cherchera les fichiers n'ayant pas ce mot-clef
	 */
	public function getByKeywords($keywords, $mode="AND") {
		return $this->storageAdapter->getByKeywords($keywords, $mode);
	}

	/**
	 * Retourne une liste de fichiers appartenant aux groupes $groups
	 * (séparés par des virgules ); si $mode est "OR", un "OU" sera appliqué
	 * entre les groupes, sinon un "ET"; si un groupe est préfixé par "!", on
	 * cherchera les fichiers n'appartenant pas à ce groupe
	 * @TODO gérer les droits
	 */
	public function getByGroups($groups, $mode="AND") {
		return $this->storageAdapter->getByGroups($groups, $mode);
	}

	/**
	 * Retourne une liste de fichiers appartenant à l'utilisateur $user
	 * @TODO gérer les droits
	 */
	public function getByUser($user) {
		return $this->storageAdapter->getByUser($user);
	}

	/**
	 * Retourne une liste de fichiers dont le type MIME est $mimetype
	 */
	public function getByMimetype($mimetype) {
		return $this->storageAdapter->getByMimetype($mimetype);
	}

	/**
	 * Retourne une liste de fichiers dont la licence est $license
	 */
	public function getByLicense($license) {
		return $this->storageAdapter->getByLicense($license);
	}

	/**
	 * Retourne une liste de fichiers en fonction de leur date (@TODO de création
	 * ou de modification ?) : si $date1 et $date2 sont spécifiées, renverra les
	 * fichiers dont la date se trouve entre les deux; sinon, comparera à $date1
	 * en fonction de $operator ("=", "<" ou ">")
	 */
	public function getByDate($dateColumn, $date1, $date2, $operator="=") {
		return $this->storageAdapter->getByDate($dateColumn, $date1, $date2, $operator);
	}

	/**
	 * Recherche avancée - retourne une liste de fichiers correspondant aux
	 * critères de recherche contenus dans $searchParams (paires de
	 * clefs / valeurs); le critère "mode" peut valoir "OR" (par défaut) ou
	 * "AND"
	 */
	public function search($searchParams=array()) {
		return $this->storageAdapter->search($searchParams);
	}

	/**
	 * Ajoute le fichier $file au stock, dans le chemin $path, avec la clef $key,
	 * les mots-clefs $keywords (séparés par des virgules), les groupes $groupes
	 * (séparés par des virgules), les permissions $permissions, la licence
	 * $license et les métadonnées $meta (portion de JSON libre). Si le fichier
	 * existe déjà, il sera remplacé
	 */
	public function addOrUpdateFile($file, $path, $name, $keywords=null, $groups=null, $permissions=null, $license=null, $meta=null) {
		return $this->storageAdapter->addOrUpdateFile($file, $path, $name, $keywords, $groups, $permissions, $license, $meta);
	}

	/**
	 * Met à jour les métadonnées du fichier identifié par $key; si
	 * $newname est fourni, renomme le fichier
	 */
	public function updateByKey($key, $newname=null, $newpath=null, $keywords=null, $groups=null, $permissions=null, $license=null, $meta=null) {
		return $this->storageAdapter->updateByKey($key, $newname, $newpath, $keywords, $groups, $permissions, $license, $meta);
	}

	/**
	 * Supprime le fichier identifié par $key; si $keepFile est true, ne
	 * supprime que la référence mais conserve le fichier dans le stockage
	 */
	public function deleteByKey($key, $keepFile=false) {
		return $this->storageAdapter->deleteByKey($key, $keepFile);
	}

	/**
	 * Retourne les attributs (métadonnées) du fichier identifié par $key,
	 * mais pas le fichier lui-même
	 */
	public function getAttributesByKey($key) {
		return $this->storageAdapter->getAttributesByKey($key);
	}
}
