<?php
/**
 * Point d'entrée (bootstrap) du service REST Cumulus
 * 
 * Toutes les requêtes doivent être redirigées vers ce fichier; un .htaccess est
 * fourni à cet effet pour Apache
 */

require 'CumulusService.php';

// Initialisation et exécution du service
$svc = new CumulusService();
$svc->run();
