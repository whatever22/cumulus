<?php

/**
 * Interface que tout adapteur de stockage doit implémenter
 */
interface CumulusInterface {

	const COLUMN_LAST_MODIFICATION_DATE = "last_modification_date";
	const COLUMN_CREATION_DATE = "creation_date";

	/**
	 * Si $inverse est true, indique à l'adapteur que les critères de recherche
	 * devront être inversés
	 * @param type $inverse
	 */
	public function setInverseCriteria($inverse);

	/**
	 * Retourne un fichier à partir de sa clef et son chemin
	 * @param type $path
	 * @param type $key
	 */
	public function getByKey($path, $key);

	/**
	 * Retourne une liste de fichiers dont les noms correspondent à $name; si
	 * $trict est true, compare avec un "=" sinon avec un "LIKE"
	 * @param type $name
	 * @param type $strict
	 */
	public function getByName($name, $strict=false);

	/**
	 * Retourne une liste de fichiers se trouvant dans le répertoire $path; si
	 * $recursive est true, cherchera dans tous les sous-répertoires
	 * @param type $path
	 * @param type $recursive
	 */
	public function getByPath($path, $recursive=false);

	/**
	 * Retourne une liste de fichiers dont les mots-clefs sont $keywords
	 * (séparés par des virgules ); si $mode est "OR", un "OU" sera appliqué
	 * entre les mots-clefs, sinon un "ET"; si un mot-clef est préfixé par "!",
	 * on cherchera les fichiers n'ayant pas ce mot-clef
	 * @param type $keywords
	 * @param type $mode
	 */
	public function getByKeywords($keywords, $mode="AND");

	/**
	 * Retourne une liste de fichiers appartenant aux groupes $groups
	 * (séparés par des virgules ); si $mode est "OR", un "OU" sera appliqué
	 * entre les groupes, sinon un "ET"; si un groupe est préfixé par "!", on
	 * cherchera les fichiers n'appartenant pas à ce groupe
	 * @TODO gérer les droits
	 * @param type $groups
	 * @param type mode
	 */
	public function getByGroups($groups, $mode="AND");

	/**
	 * Retourne une liste de fichiers appartenant à l'utilisateur $user
	 * @TODO gérer les droits
	 * @param type $user
	 */
	public function getByUser($user);

	/**
	 * Retourne une liste de fichiers dont le type MIME est $mimetype
	 * @param type $mimetype
	 */
	public function getByMimetype($mimetype);

	/**
	 * Retourne une liste de fichiers en fonction de leur date de création ou de
	 * modification ($dateColumn) : si $date1 et $date2 sont spécifiées,
	 * renverra les fichiers dont la date se trouve entre les deux; sinon,
	 * comparera à $date1 en fonction de $operator ("=", "<" ou ">")
	 * @param type $date1
	 * @param type $date2
	 * @param type $operator
	 */
	public function getByDate($dateColumn, $date1, $date2, $operator="=");

	/**
	 * Recherche avancée - retourne une liste de fichiers correspondant aux
	 * critères de recherche contenus dans $searchParams (paires de
	 * clefs / valeurs); le critère "mode" peut valoir "OR" (par défaut) ou
	 * "AND"
	 * @param type $searchParams
	 */
	public function search($searchParams=array());

	/**
	 * Ajoute le fichier $file au stock, dans le chemin $path, avec la clef $key,
	 * les mots-clefs $keywords (séparés par des virgules) et les métadonnées
	 * $meta (portion de JSON libre); si $key est null, une clef sera attribuée
	 * @param type $file
	 * @param type $path
	 * @param type $key
	 * @param type $keywords
	 * @param type $meta
	 */
	public function addFile($file, $path, $key=null, $keywords=null, $meta=null);

	/**
	 * Remplace le contenu (si $file est spécifié) et / ou les métadonnées du
	 * fichier $key situé dans $path
	 * @param type $file
	 * @param type $path
	 * @param type $key
	 * @param type $keywords
	 * @param type $meta
	 */
	public function updateByKey($file, $path, $key, $keywords=null, $meta=null);

	/**
	 * Supprime le fichier $key situé dans $path
	 * @param type $path
	 * @param type $key
	 */
	public function deleteByKey($path, $key);

	/**
	 * Retourne les attributs (métadonnées) du fichier $key situé dans $path,
	 * mais pas le fichier lui-même
	 * @param type $path
	 * @param type $key
	 */
	public function getAttributesByKey($path, $key);
}