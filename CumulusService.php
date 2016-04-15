<?php

require_once 'BaseService.php';
require_once 'Cumulus.php';

/**
 * API REST pour le stockage de fichiers Cumulus
 */
class CumulusService extends BaseService {

	/** Bibliothèque Cumulus */
	protected $lib;

	/** Autodocumentation en JSON */
	public static $AUTODOC_PATH = "autodoc.json";

	/** Motif d'expression régulière pour détecter les références de fichiers */
	public static $REF_PATTERN = '`https?://`';

	public function __construct() {
		// lib Cumulus
		$this->lib = new Cumulus();

		// ne pas indexer - placé ici pour simplifier l'utilisation avec nginx
		// (pas de .htaccess)
		header("X-Robots-Tag: noindex, nofollow", true);

		parent::__construct();
	}

	/**
	 * Renvoie plusieurs résultats $results dans un objet JSON, en remplaçant
	 * les chemins de stockage par des liens de téléchargement
	 */
	protected function sendMultipleResults($results, $errorMessage="no results", $errorCode=404) {
		if ($results == false) {
			$this->sendError($errorMessage, $errorCode);
		} else {
			// création des liens de téléchargement
			$this->buildLinksAndRemoveStoragePaths($results);
			$this->sendJson(
				array(
					"count" => count($results),
					"results" => $results
				)
			);
		}
	}

	/**
	 * Renvoie une liste de listes de résultats (pour getFolderContents() par
	 * exemple) dans un objet JSON, en remplaçant les chemins de stockage par
	 * des liens de téléchargement
	 */
	protected function sendMultipleMixedResults($results, $errorMessage="no results", $errorCode=404) {
		if ($results == false) {
			$this->sendError($errorMessage, $errorCode);
		} else {
			$mixedResults = array();
			// traitement des sous-listes
			foreach ($results as $k => $subList) {
				// comptage des éléments
				$partialResult = array(
					"count" => count($subList),
					"results" => $subList
				);
				// création des liens de téléchargement
				$this->buildLinksAndRemoveStoragePaths($partialResult["results"]);
				$mixedResults[$k] = $partialResult;
			}
			$this->sendJson($mixedResults);
		}
	}

	/**
	 * Appelle $this->buildLinkAndRemoveStoragePath() sur chaque fichier du jeu
	 * de données
	 */
	protected function buildLinksAndRemoveStoragePaths(&$results) {
		foreach ($results as &$r) {
			$this->buildLinkAndRemoveStoragePath($r);
		}
	}

	/**
	 * Ajoute un lien de téléchargement "href" au fichier, et supprime la valeur
	 * de "storage_path" (par sécurité)
	 */
	function buildLinkAndRemoveStoragePath(&$r) {
		// fichier stocké ou référence vers une URL ?
		if (isset($r['storage_path'])) { // élimine les dossiers
			if (preg_match(self::$REF_PATTERN, $r['storage_path']) != false) {
				$r['href'] = $r['storage_path'];
			} else {
				$r['href'] = $this->buildLink($r['fkey']);
			}
			unset($r['storage_path']);
		}
	}

	/**
	 * Retourne un lien de téléchargement relatif à l'URL de base du service,
	 * pour une clef de fichier donnée
	 */
	protected function buildLink($key) {
		if (empty($key)) {
			return false;
		}
		$href = $this->domainRoot . $this->baseURI . '/' . $key;
		return $href;
	}

	/**
	 * Envoie le fichier $file au client en forçant le téléchargement; s'il
	 * s'agit d'une URL et non d'un fichier stocké, redirige vers cette URL
	 * @TODO gérer les "range" pour pouvoir interrompre / reprendre un téléchargement
	 * => http://www.media-division.com/the-right-way-to-handle-file-downloads-in-php/
	 * @TODO demander la lecture du fichier à la couche du dessous
	 */
	protected function sendFile($file, $name, $size, $mimetype='application/octet-stream') {
		// fichier stocké ou référence vers une URL ?
		if (preg_match(self::$REF_PATTERN, $file) != false) {
			// URL => redirection
			header('Location: ' . $file);
		} else {
			// fichier stocké => téléchargement
			if (! file_exists($file)) {
				$this->sendError("file does not exist in storage");
			}
			header('Content-Type: ' . $mimetype);
			header('Content-Disposition: attachment; filename="' . $name . '"');
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Pragma: public');
			header('Content-Length: ' . $size);
			// envoi progressif du contenu
			// http://www.media-division.com/the-right-way-to-handle-file-downloads-in-php/
			set_time_limit(0);
			$f = @fopen($file,"rb");
			while(!feof($f)) {
				print(fread($f, 1024*8));
				ob_flush();
				flush();
			}
		}
		exit;
	}

	/**
	 * Obtenir un fichier : plusieurs manières dépendamment de l'URI
	 */
	protected function get() {
		// réponse positive par défaut;
		http_response_code(200);

		// il faut au moins une ressource : clef ou méthode
		// @TODO permettre de faire getFolderContents() sur la racine
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
			case "get-folders":
				$this->getFolders();
				break;
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
			case "by-license":
				$this->getByLicense();
				break;
			case "search":
				$this->search();
				break;
			default:
				$this->getByKeyOrCompletePath();
		}
	}

	/**
	 * Autodescription du service
	 */
	protected function usage() {
		$rootUri = $this->domainRoot . $this->baseURI . "/";
		// lecture de l'autodoc en JSON et remplacement de l'URI racine
		if (file_exists(self::$AUTODOC_PATH)) {
			$infos = json_decode(file_get_contents(self::$AUTODOC_PATH), true);
			foreach ($infos['uri-patterns'] as &$up) {
				foreach($up as $k => &$v) {
					$up[$k] = str_replace("__ROOTURI__", $rootUri, $up[$k]);
				}
			}
			$this->sendJson($infos);
		} else {
			$this->sendError("wrong URI");
		}
	}

	/**
	 * GET http://tb.org/cumulus.php/clef
	 * GET http://tb.org/cumulus.php/chemin/arbitraire/nom.ext
	 * 
	 * Récupère le fichier identifié par clef ou par le couple chemin / nom
	 * (déclenche son téléchargement); si le chemin désigne un dossier, renvoie
	 * le descriptif du dossier: liste des fichiers et des sous-dossiers
	 */
	protected function getByKeyOrCompletePath() {
		$fullPath = rtrim('/' . implode('/', $this->resources), '/');
		$nameOrKey = array_pop($this->resources);
		$path = '/' . implode('/', $this->resources);

		$recursive = false;
		if ($this->getParam('R') !== null) {
			$recursive = true;
		}

		// A-t-on passé une clef ou un chemin ?
		$isFolder = false;
		$key = $nameOrKey;
		if (! $this->lib->isKey($nameOrKey)) {
			// pas de clef - est-ce un dossier ou un fichier ?
			if ($this->lib->isFolder($fullPath)) { // dossier
				$isFolder = true;
			} else { // fichier
				// calcul de la clef
				$key = $this->lib->computeKey($path, $nameOrKey);
			}
		}

		// Va chercher, Lycos !
		if ($isFolder) {
			// détails du dossier
			$files = $this->lib->getFolderContents($fullPath, $recursive);
			$this->sendMultipleMixedResults($files);
		} else {
			//echo "getByKey : [$path] [$key]\n";
			$file = $this->lib->getByKey($key);

			if ($file == false) {
				$this->sendError("file not found", 404);
			} else {
				$this->sendFile($file['storage_path'], $file['name'], $file['size'], $file['mimetype']);
			}
		}
	}

	/**
	 * GET http://tb.org/cumulus.php/get-folders/root/path
	 * GET http://tb.org/cumulus.php/get-folders/root/path?R
	 * 
	 * Renvoie une liste de dossiers se trouvant sous "/root/path"; si ?R est
	 * utilisé, renvoie aussi leurs sous-dossiers
	 */
	protected function getFolders() {
		array_shift($this->resources);
		$path = '/' . implode('/', $this->resources);
		$recursive = false;
		if ($this->getParam('R') !== null) {
			$recursive = true;
		}

		//echo "getFolders : [$path]\n";
		//var_dump($recursive);
		$folders = $this->lib->getFolders($path, $recursive);

		$this->sendJson($folders);
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
	 * 
	 * GET http://tb.org/cumulus.php/by-path/mon/super/chemin?FOLDERS
	 * Renvoie une liste de fichiers (les clefs et les attributs) présents dans
	 * un dossier dont le chemin est /mon/super/chemin ou un sous-dossier et y
	 * ajoute une liste des "dossiers" présents sous ce chemin
	 */
	protected function getByPath() {
		array_shift($this->resources);
		$path = '/' . implode('/', $this->resources);
		$recursive = false;
		if ($this->getParam('R') !== null) {
			$recursive = true;
		}
		$includeFolders = false;
		if ($this->getParam('FOLDERS') !== null) {
			$includeFolders = true;
		}

		//echo "getByPath : [$path]\n";
		//var_dump($recursive);
		$files = $this->lib->getByPath($path, $recursive, $includeFolders);

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
	 * GET http://tb.org/cumulus.php/by-license/CC-BY-SA
	 * 
	 * Renvoie une liste de fichiers (les clefs et les attributs) ayant une
	 * licence CC-BY-SA
	 */
	protected function getByLicense() {
		$license = isset($this->resources[1]) ? $this->resources[1] : null;

		// echo "getByLicense : [$license]\n";
		$files = $this->lib->getByLicense($license);

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
		$this->sendError("PUT is not supported at the moment", 405);
	}

	/**
	 * Copie le corps de la requête dans un fichier temporaire et retourne un
	 * tableau partiellement compatible avec le format de $_FILES
	 */
	protected function copyRequestBodyToTmpFile() {
		// flux en mode binaire
		$stream = fopen('php://input', 'rb');
		$tmpFileName = '/tmp/' . md5(microtime());
		$tmpFile = fopen($tmpFileName, 'wb');

		// écriture progressive pour économiser la mémoire p/r file_put_contents
		while (!feof($stream)) {
		   $buffer = fread($stream, 4096); // 4096... ça ou autre chose...
		   fwrite($tmpFile, $buffer);
		}
		// fermeture propre
		fclose($stream);
		fclose($tmpFile);

		// si le fichier contient quelque chose on le renvoie, sinon on le
		// supprime et on renvoie false
		$fileSize = filesize($tmpFileName);
		$stats = false;
		if ($fileSize == 0) {
			unlink($tmpFileName);
		} else {
			$stats = array(
				'tmp_name' => $tmpFileName,
				'size' => $fileSize
			);
		}
		return $stats;
	}

	/**
	 * Décode le contenu du fichier, encodé en base 64, et l'écrit dans un
	 * fichier temporaire; retourne un tableau partiellement compatible avec le
	 * format de $_FILES
	 * @TODO accepter un pointeur pour les gros fichiers (nécessite d'abord de
	 *		modifier readRequestBody()), pour économiser la mémoire
	 */
	protected function copyBase64ToTmpFile(&$base64FileContents) {
		if (empty($base64FileContents)) {
			return false;
		}

		// décodage de la base64
		$decodedFileContents = base64_decode($base64FileContents);

		// écriture du fichier temporaire
		$tmpFileName = '/tmp/' . md5(microtime());
		file_put_contents($tmpFileName, $decodedFileContents);

		$stats = array(
			'tmp_name' => $tmpFileName,
			'size' => filesize($tmpFileName)
		);
		return $stats;
	}

	/**
	 * Version de explode() qui préserve les valeurs NULL - permet de
	 * différencier '' de NULL dans les paramètres multiples comme "keywords"
	 */
	protected function explode($delimiter, $string) {
		if ($string === null) {
			return null;
		} else {
			return explode($delimiter, $string);
		}
	}

	/**
	 * Ajoute un fichier et renvoie sa clef et ses attributs; si aucun fichier
	 * n'est spécifié, modifie les métadonnées de la clef ciblée
	 */
	protected function post() {
		$nameOrKey = array_pop($this->resources);
		$path = '/' . implode('/', $this->resources);

		// A-t-on passé une clef (mise à jour) ou un couple chemin / nom
		// (nouveau fichier) ?
		$key = $nameOrKey;
		if (! $this->lib->isKey($nameOrKey)) {
			// calcul de la clef
			$key = $this->lib->computeKey($path, $nameOrKey);
		}

		// extraction des paramètres POST
		$newname = $this->getParam('newname');
		$newpath = $this->getParam('newpath');
		$keywords = $this->explode(',', $this->getParam('keywords'));
		$groups = $this->explode(',', $this->getParam('groups'));
		$permissions = $this->getParam('permissions');
		$license = $this->getParam('license');
		$meta = json_decode($this->getParam('meta'), true);

		$file = false;
		$serverContentType = '';
		if (! empty($_SERVER["CONTENT_TYPE"])) {
			$serverContentType = $_SERVER["CONTENT_TYPE"];
		}
		// détection de la méthode d'envoi
		$isMPFD = strtolower(substr($serverContentType, 0, 19)) == 'multipart/form-data';
		if ($isMPFD) { // envoi de formulaire classique avec multipart/form-data
			// détection : fichier ou référence (URL) ?
			if (! empty($_FILES['file'])) {
				// fichier uploadé
				$file = $_FILES['file'];
			} else {
				// référence sur fichier
				$fileParam = $this->getParam('file');
				if (preg_match(self::$REF_PATTERN, $fileParam) != false) {
					// référence
					$file = array(
						'url' => $fileParam,
						'size' => count($fileParam)
					);
				} // sinon pas de fichier spécifié => modif de métadonnées
			}
		} else {
			$isJSON = strtolower(substr($serverContentType, 0, 16)) == 'application/json';
			if ($isJSON) { // fichier en base64 dans le paramètre "file"
				$requestBody = $this->readRequestBody();
				$jsonData = json_decode($requestBody, true);

				// extraction des paramètres
				$keywords = $this->getParam('keywords', null, $jsonData);
				$groups = $this->getParam('groups', null, $jsonData);
				$permissions = $this->getParam('permissions', null, $jsonData);
				$license = $this->getParam('license', null, $jsonData);
				$meta = $this->getParam('meta', null, $jsonData);

				// détection : fichier ou référence (URL) ?
				if (! empty($jsonData['file']) && (preg_match(self::$REF_PATTERN, $jsonData['file']) != false)) {
					// référence
					$file = array(
						'url' => $jsonData['file'],
						'size' => count($jsonData['file'])
					);
				} else {
					// copie du contenu base64 dans un fichier temporaire
					$file = $this->copyBase64ToTmpFile($jsonData['file']);
				}
			} else { // fichier dans le corps de la requête
				$file = $this->copyRequestBodyToTmpFile();
			}
		}

		$info = false;
		if ($file == null) {
			// mise à jour métadonnées seulement
			$info = $this->lib->updateByKey($key, $newname, $newpath, $keywords, $groups, $permissions, $license, $meta);
		} else {
			// ajout / mise à jour de fichier
			$info = $this->lib->addOrUpdateFile($file, $path, $nameOrKey, $keywords, $groups, $permissions, $license, $meta);
		}

		if ($info == false) {
			$this->sendError("error while sending file");
		} else {
			$this->buildLinkAndRemoveStoragePath($info);
			$this->sendJson($info);
		}
	}

	/**
	 * Supprime un fichier par sa clef, ou par sa clef plus son chemin
	 */
	protected function delete() {
		$key = array_pop($this->resources);

		//echo "delete : [$key]\n";
		$info = $this->lib->deleteByKey($key);

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

		//echo "options : [$key]\n";
		$file = $this->lib->getAttributesByKey($key);

		if ($file == false) {
			$this->sendError("file not found", 404);
		} else {
			$this->buildLinkAndRemoveStoragePath($file);
			$this->sendJson($file);
		}
	}
}