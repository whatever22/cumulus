<?php

// définir ici les paramètres de connexion MySQL
function connexionCumulus() {
	// touche à ça
	$hote = "";
	$port = "3306";
	$utilisateur = "";
	$mdp = "";
	$base = "cumulus";
	// touche pas à ça
	$dsn = "mysql:host=" . $hote . ";port=" . $port . ";dbname=" . $base;
	$bd = new PDO($dsn, $utilisateur, $mdp, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
	return $bd;
}