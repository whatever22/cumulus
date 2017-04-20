<?php

require_once "config.php";

$actions = array("importer_nouveaux_fichiers", "reconstruire_mimetype_et_taille", "detecter_doublons");

function usage() {
	global $argv;
	global $actions;
	echo "Utilisation: " . $argv[0] . " action\n";
	echo "\t" . "action: " . implode(" | ", $actions) . "\n";
	exit;
}

if ($argc < 2 || !in_array($argv[1], $actions)) {
	usage();
}

$action = $argv[1];
// arguments de l'action : tout moins le nom du script et le nom de l'action
array_shift($argv);
array_shift($argv);
$argc -= 2;

// connexion aux BDs
$bdCumulus = connexionCumulus();
$bdCumulus->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// action en fonction du 1er argument de la ligne de commande
switch($action) {
	case "importer_nouveaux_fichiers":
		importer_nouveaux_fichiers($argc, $argv);
		break;
	case "reconstruire_mimetype_et_taille":
		reconstruire_mimetype_et_taille($argc, $argv);
		break;
	case "detecter_doublons":
		detecter_doublons($argc, $argv);
		break;
	default:
		throw new Exception('une action déclarée dans $actions devrait avoir un "case" correspondant dans le "switch"');
}

// Cherche des doublons dans la base de données : deux ou plusieurs fichiers
// ayant exactement le même chemin et le même nom (incluant l'extension);
// affiche un rapport avec les différences
function detecter_doublons($argc, $argv) {
	global $bdCumulus;

	// les "COLLATE" servent à être sensible à la casse
	$reqDoub = "SELECT * FROM cumulus_files "
		. "WHERE (path COLLATE utf8_bin, name COLLATE utf8_bin) IN "
		. "(SELECT path, name FROM cumulus_files GROUP BY path COLLATE utf8_bin, name COLLATE utf8_bin HAVING count(fkey) > 1) "
		. "ORDER BY path, name";

	$resDoub = $bdCumulus->query($reqDoub);
	$doublons = array();
	$nbDoublons = 0;
	$nbFichiers = 0;
	$nbDossiers = 0;
	// organisation préliminaire (on sait jamais, si les résultats arrivent dans
	// le désordre)
	while ($ligne = $resDoub->fetch()) {
		if (! isset($doublons[$ligne['path']])) {
			$doublons[$ligne['path']] = array();
			$nbDossiers++;
		}
		if (! isset($doublons[$ligne['path']][$ligne['name']])) {
			$doublons[$ligne['path']][$ligne['name']] = array();
			$nbFichiers++;
		}
		// pour cette paire chemin / nom, une occurrence (doublon) de plus
		$doublons[$ligne['path']][$ligne['name']][] = $ligne;
		$nbDoublons ++;
	}

	// affichage sympatoche
	//var_dump($doublons);
	foreach ($doublons as $dossier => $fichiers) {
		echo "==== $dossier ====" . PHP_EOL;
		echo "> " . count($fichiers) . " fichier(s)" . PHP_EOL;
		foreach ($fichiers as $fichier => $occurrences) {
			echo "  == $fichier ==" . PHP_EOL;
			echo "  > " . count($occurrences) . " occurrence(s)" . PHP_EOL;
			foreach ($occurrences as $oc) {
				// mise en avant des différences
				echo "  " . $oc['fkey'] . " :" . PHP_EOL;
				foreach ($oc as $k => $v) {
					if (!is_numeric($k)) {
						echo "    [$k] => $v" . PHP_EOL;
					}
				}
			}
		}
	}

	// résumé
	echo "--------------------------------------------------------------" . PHP_EOL;
	echo ($nbDoublons - $nbFichiers) . " doublons trouvé(s) pour $nbFichiers fichier(s) dans $nbDossiers dossier(s)" . PHP_EOL;
}

/**
 * Lit toutes les entrées de fichiers de la base de données et analyse le
 * fichier associé sur le disque, pour en extraire le mimetype et la taille;
 * met à jour l'entrée de fichier (utile après une migration de fichiers depuis
 * une autre BDD)
 */
function reconstruire_mimetype_et_taille($argc, $argv) {
	global $bdCumulus;

	// tous les fichiers
	$reqFic = "SELECT fkey, storage_path FROM cumulus_files";
	$resFic = $bdCumulus->query($reqFic);
	$fichiers = array();
	while ($ligne = $resFic->fetch()) {
		$fichiers[$ligne['fkey']] = $ligne['storage_path'];
	}
	//var_dump($fichiers);
	echo count($fichiers) . " fichiers trouvés\n";

	$cptUpd = 0;
	$cptIgn = 0;
	// analyse et mise à jour
	foreach ($fichiers as $k => $f) {
		if (file_exists($f)) {
			// détection du mimetype
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mimetype = finfo_file($finfo, $f);
			finfo_close($finfo);
			// détection de la taille du fichier
			$taille = filesize($f);
			//echo "TYPE: [$mimetype], TAILLE: [$taille]\n";
			$reqUpd = "UPDATE cumulus_files SET mimetype='$mimetype', size=$taille WHERE fkey='$k'";
			$bdCumulus->exec($reqUpd);
			$cptUpd++;
		} else {
			echo "FICHIER ABSENT [$f]\n";
			$cptIgn++;
		}
	}

	// résumé
	echo "$cptUpd fichiers mis à jour\n";
	echo "$cptIgn fichiers ignorés\n";
}

// Parcourt le dossier de stockage à la recherche de fichiers n'étant pas
// présents dans la base de données, et les importe.
// Utile pour importer de nouveaux fichiers en conservant leur chemin d'origine.
// 
// si $argv[0] contient un chemin commençant à la racine du dossier de stockage
// (ex: "equipe/intranet/"), seul ce dossier sera analysé
function importer_nouveaux_fichiers($argc, $argv) {
	global $bdCumulus;
	global $racineStockage;

	if ($argc == 0) {
		echo "Utilisation: importer_nouveaux_fichiers /chemin/du/dossier [options]" . PHP_EOL;
		echo "Pour analyser l'ensemble du dossier de stockage, saisir \"/\" comme chemin de dossier" . PHP_EOL;
		echo "options : suite de clef=valeur séparées par des \"&\", pour indiquer les métadonnées par défaut" . PHP_EOL;
		echo "\tex: \"owner=123&groups=mon groupe,un_autre-groupe&permissions=--\"" . PHP_EOL;
		echo "\tclefs disponibles:" . PHP_EOL;
		echo "\t- owner" . PHP_EOL;
		echo "\t- groups" . PHP_EOL;
		echo "\t- permissions" . PHP_EOL;
		echo "\t- keywords" . PHP_EOL;
		echo "\t- license" . PHP_EOL;
		exit;
	}
	// traitement des options
	$options = [];
	if ($argc > 1) {
		$optionsTmp = $argv[1];
		$optionsTmp = explode('&', $optionsTmp);
		foreach ($optionsTmp as $opt) {
			$kv = explode('=', $opt);
			$val = $kv[1];
			/*if (strpos($val, ',') !== false) {
				$val = explode(',', $val);
			}*/
			$options[$kv[0]] = $val;
		}
	}

	require_once __DIR__ . '/../Cumulus.php';
	$lib = new Cumulus();

	$cheminStockage = $lib->getStoragePath();
	$cheminStockage = rtrim($cheminStockage, "/");
	$cheminAbsDossier = $cheminStockage;

	$cheminDossier = $argv[0];
	$cheminDossier = trim($cheminDossier, "/");
	if ($cheminDossier != '') {
		$cheminDossier = '/' . $cheminDossier;
	}
	$cheminAbsDossier .= $cheminDossier;

	if (!is_dir($cheminAbsDossier)) {
		throw new Exception("[$cheminAbsDossier] n'existe pas ou n'est pas un dossier");
	}
	if (!is_readable($cheminAbsDossier)) {
		throw new Exception("impossible de lire le dossier [$cheminAbsDossier]");
	}

	$nbFichiers = 0;
	$nbDossiers = 0;
	$nbSucces = 0;
	$nbErreurs = 0;
	importer_un_dossier($cheminStockage, $cheminDossier, $lib, $bdCumulus, $options, $nbFichiers, $nbDossiers, $nbSucces, $nbErreurs);

	echo $nbDossiers . " dossier(s) / $nbFichiers fichier(s) analysé(s)" . PHP_EOL;
	if ($nbSucces + $nbErreurs == 0) {
		echo "Rien à importer" . PHP_EOL;
	} else {
		echo "$nbSucces fichier(s) importé(s) / $nbErreurs erreur(s) sur " . ($nbSucces + $nbErreurs) . " tentative(s)" . PHP_EOL;
	}
}

// parcourt un dossier à importer, récursivement
function importer_un_dossier($cheminStockage, $cheminDossier, &$lib, &$bdCumulus, &$options, &$nbFichiers, &$nbDossiers, &$nbSucces, &$nbErreurs) {
	// parcours du dossier
	$cheminAbsDossier = $cheminStockage . '/' . $cheminDossier;
	$d = opendir($cheminAbsDossier);
	while ($f = readdir($d)) {
		if ($f == "." || $f == "..") continue;
		// chemin relatif du fichier
		$cheminFichier = $cheminDossier . '/' . $f;
		$cheminAbsFichier = $cheminStockage . '/' . $cheminFichier;
		// dossier ?
		if (is_dir($cheminAbsFichier)) {
			importer_un_dossier($cheminStockage, $cheminFichier, $lib, $bdCumulus, $options, $nbFichiers, $nbDossiers, $nbSucces, $nbErreurs);
		} else { // fichier
			$nbFichiers++;
			//echo "Trouvé un fichier : [$cheminFichier]\n";
			traiter_fichier($cheminStockage, $cheminFichier, $f, $lib, $bdCumulus, $options, $nbSucces, $nbErreurs);
		}
	}
	$nbDossiers++;
}

// lors du parcours d'un dossier à importer, traite un fichier : vérifie s'il
// est connu dans la BDD, et si ce n'est pas le cas, l'importe
function traiter_fichier($cheminStockage, $cheminFichier, $f, &$lib, &$bdCumulus, &$options, &$nbSucces, &$nbErreurs) {
	$reqExist = "SELECT count(*) as existe "
		. "FROM cumulus_files "
		. "WHERE path COLLATE utf8_bin = " . $bdCumulus->quote(dirname($cheminFichier)) . "AND name COLLATE utf8_bin = " . $bdCumulus->quote($f);
	//var_dump($reqExist);
	$resExist = $bdCumulus->query($reqExist);
	if (! $resExist) {
		// erreur BDD
		$nbErreurs++;
		return false;
	}
	$resExist = $resExist->fetch();
	if (! $resExist) {
		// erreur WTF
		$nbErreurs++;
		return false;
	}
	if ($resExist['existe'] == 0) {
		$cheminAbsFichier = $cheminStockage . $cheminFichier;
		echo "> import de [$cheminFichier]" . PHP_EOL;

		// mimetype
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mimetype = finfo_file($finfo, $cheminAbsFichier);
		finfo_close($finfo);
		// taille
		$taille = filesize($cheminAbsFichier);
		// last_modification_date
		$last_modification_date = date('Y-m-d H:i:s', filemtime($cheminAbsFichier));
		// creation_date
		// filectime risque de retourner une date très récente si on vient de
		// copier-coller le fichier, alors on prend plutôt le filemtime
		$creation_date = $last_modification_date;

		// owner
		$owner = null;
		if (! empty($options['owner'])) $owner = $options['owner'];
		// groups
		$groups = null;
		if (! empty($options['groups'])) $groups = $options['groups'];
		// permissions
		$permissions = 'ww';
		if (! empty($options['permissions'])) $permissions = $options['permissions'];
		// keywords
		$keywords = null;
		if (! empty($options['keywords'])) $keywords = $options['keywords'];
		// license
		$license = null;
		if (! empty($options['license'])) $license = $options['license'];

		// meta
		$meta = json_encode([
			"importe_par" => "script maintenance",
			"date_import" => date("Y-m-d")
		]);

		// fkey
		$fkey = $lib->computeKey(dirname($cheminFichier), $f);

		$reqIns = "INSERT INTO cumulus_files VALUES ("
			. $bdCumulus->quote($fkey) . ', '
			. $bdCumulus->quote($f) . ', '
			. $bdCumulus->quote(dirname($cheminFichier)) . ', '
			. $bdCumulus->quote($cheminAbsFichier) . ', '
			. $bdCumulus->quote($mimetype) . ', '
			. $bdCumulus->quote($taille) . ', '
			. $bdCumulus->quote($owner) . ', '
			. $bdCumulus->quote($groups) . ', '
			. $bdCumulus->quote($permissions) . ', '
			. $bdCumulus->quote($keywords) . ', '
			. $bdCumulus->quote($license) . ', '
			. $bdCumulus->quote($meta) . ', '
			. $bdCumulus->quote($creation_date) . ', '
			. $bdCumulus->quote($last_modification_date)
			. ")";

		$ok = $bdCumulus->exec($reqIns);
		if ($ok) {
			$nbSucces++;
		} else {
			$nbErreurs++;
		}
	}

	return true;
}
