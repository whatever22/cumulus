<?php

require_once "config.php";

$actions = array("reconstruire_mimetype_et_taille");

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
	case "reconstruire_mimetype_et_taille":
		reconstruire_mimetype_et_taille($argc, $argv);
		break;
	default:
		throw new Exception('une action déclarée dans $actions devrait avoir un "case" correspondant dans le "switch"');
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
