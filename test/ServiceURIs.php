<?php

/* 
 * Appelle tous les motifs d'URL définis dans la spec. de l'API et vérifie que
 * le service retourne un code de succès - ne vérifie pas le contenu, juste la
 * validité des appels
 * Utilise la configuration config/service.ini
 */

$CHEMIN_CONFIG = "config/service.json";

// détection de la position du fichier de config
$cwd = getcwd();
if (strpos($cwd, "test") !== false) {
	// si on n'et pas dans le dossier "test", on considère qu'on est dans le
	// dossier racine de cumulus (cas le plus fréquent)
	$CHEMIN_CONFIG = "../" . $CHEMIN_CONFIG;
}

// chargement de la config
if (file_exists($CHEMIN_CONFIG)) {
	$config = json_decode(file_get_contents($CHEMIN_CONFIG), true);
} else {
	throw new Exception("Le fichier " . $CHEMIN_CONFIG . " n'existe pas");
}

$baseURI = $config['domain_root'] . $config['base_uri'];

$URIs = $config['test']['uris'];

$success = 0;
$failures = 0;

foreach ($URIs as $URI) {
	$URI = $baseURI . $URI;
	file_get_contents($URI);
	$responseCodeHeader = $http_response_header[0];
	$responseCode = extractCode($responseCodeHeader);
	$status = checkResponse($responseCode);
	if ($status === true) {
		echo "OK $URI\n";
		$success++;
	}
	if ($status === false) {
		echo "KO $URI\n";
		$failures++;
	}
}

echo count($URIs) . " URIs tested\n";
echo $success . " succeeded\n";
echo $failures . " failed\n";

// lib

function extractCode($header) {
	$regExp = '/^HTTP.+ ([0-9]+) .+$/';
	$matches = array();
	preg_match($regExp, $header, $matches);
	return $matches[1];
}

function checkResponse($code /* string */) {
	if ($code[0] == '2') {
		return true;
	}
	if ($code[0] == '4') {
		return false;
	}
	throw new Exception("Code is neither 2** nor 4** : " + $code);
}