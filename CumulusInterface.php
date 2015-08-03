<?php

/**
 * Interface que tout adapteur de stockage doit implémenter
 * 
 * Utiliser les Exceptions pour déclencher des erreurs - elles seront récupérées
 * par le service REST et transformées en erreurs HTTP
 */
interface CumulusInterface {

	const COLUMN_LAST_MODIFICATION_DATE = "last_modification_date";
	const COLUMN_CREATION_DATE = "creation_date";

	/**
	 * Adapteur d'authentification / gestion des droits (doit être facultatif)
	 */
	public function setAuthAdapter($adapter);

	/**
	 * Si $inverse est true, indique à l'adapteur que les critères de recherche
	 * devront être inversés
	 */
	public function setInverseCriteria($inverse);

	/**
	 * Retourne un fichier à partir de sa clef et son chemin
	 */
	public function getByKey($path, $key);

	/**
	 * Retourne une liste de fichiers dont les noms correspondent à $name; si
	 * $trict est true, compare avec un "=" sinon avec un "LIKE"
	 */
	public function getByName($name, $strict=false);

	/**
	 * Retourne une liste de fichiers se trouvant dans le répertoire $path; si
	 * $recursive est true, cherchera dans tous les sous-répertoires
	 */
	public function getByPath($path, $recursive=false);

	/**
	 * Retourne une liste de fichiers dont les mots-clefs sont $keywords
	 * (séparés par des virgules ); si $mode est "OR", un "OU" sera appliqué
	 * entre les mots-clefs, sinon un "ET"; si un mot-clef est préfixé par "!",
	 * on cherchera les fichiers n'ayant pas ce mot-clef
	 */
	public function getByKeywords($keywords, $mode="AND");

	/**
	 * Retourne une liste de fichiers appartenant aux groupes $groups
	 * (séparés par des virgules ); si $mode est "OR", un "OU" sera appliqué
	 * entre les groupes, sinon un "ET"; si un groupe est préfixé par "!", on
	 * cherchera les fichiers n'appartenant pas à ce groupe
	 * @TODO gérer les droits
	 */
	public function getByGroups($groups, $mode="AND");

	/**
	 * Retourne une liste de fichiers appartenant à l'utilisateur $user
	 * @TODO gérer les droits
	 */
	public function getByUser($user);

	/**
	 * Retourne une liste de fichiers dont la licence est $license
	 */
	public function getByLicense($license);

	/**
	 * Retourne une liste de fichiers dont le type MIME est $mimetype
	 */
	public function getByMimetype($mimetype);

	/**
	 * Retourne une liste de fichiers en fonction de leur date de création ou de
	 * modification ($dateColumn) : si $date1 et $date2 sont spécifiées,
	 * renverra les fichiers dont la date se trouve entre les deux; sinon,
	 * comparera à $date1 en fonction de $operator ("=", "<" ou ">")
	 */
	public function getByDate($dateColumn, $date1, $date2, $operator="=");

	/**
	 * Recherche avancée - retourne une liste de fichiers correspondant aux
	 * critères de recherche contenus dans $searchParams (paires de
	 * clefs / valeurs); le critère "mode" peut valoir "OR" (par défaut) ou
	 * "AND"
	 */
	public function search($searchParams=array());

	/**
	 * Ajoute le fichier $file au stock, dans le chemin $path, avec la clef $key,
	 * les mots-clefs $keywords (séparés par des virgules), les groupes $groupes
	 * (séparés par des virgules), les permissions $permissions, la licence
	 * $license et les métadonnées $meta (portion de JSON libre). Si le fichier
	 * existe déjà, il sera remplacé
	 */
	public function addOrUpdateFile($file, $path, $key, $keywords=null, $groups=null, $permissions=null, $license=null, $meta=null);

	/**
	 * Met à jour les métadonnées du fichier identifié par $key / $path; si
	 * $newkey est fourni, renomme le fichier
	 */
	public function updateByKey($path, $key, $newkey=null, $keywords=null, $groups=null, $permissions=null, $license=null, $meta=null);

	/**
	 * Supprime le fichier $key situé dans $path; si $keepFile est true, ne
	 * supprime que la référence mais conserve le fichier dans le stockage
	 */
	public function deleteByKey($path, $key, $keepFile=false);

	/**
	 * Retourne les attributs (métadonnées) du fichier $key situé dans $path,
	 * mais pas le fichier lui-même
	 */
	public function getAttributesByKey($path, $key);
}