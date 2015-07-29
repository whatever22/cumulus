<?php

class StockageDisque {
	
	// attention $racine_fichiers doit contenir un slash terminal
	protected $racine_fichiers = "/home/aurelien/web/cumulus/files/";
	protected $droits = 0755;
	protected $ds = DIRECTORY_SEPARATOR;
	
	public function getCheminFichier($chemin_relatif) {
		return $this->racine_fichiers.$this->desinfecterCheminFichier($chemin_relatif);
	}
	
	public function getContenuDossier($chemin_dossier) {
		$fichiers = scandir($this->getCheminDossierComplet($chemin_dossier));
		return array_diff($fichiers, array('..', '.'));
	}
	
	public function getFichiersADossier($chemin_dossier) {
		return array_filter(glob($this->getCheminDossierComplet($chemin_dossier).'*'), 'is_dir');
	}

	public function getDossiersADossier($chemin_dossier) {
		return array_filter(glob($this->getCheminDossierComplet($chemin_dossier).'*'), 'is_file');
	}
	
	protected function getCheminDossierComplet($chemin_dossier) {
		return $this->racine_fichiers.$this->desinfecterCheminFichier($chemin_dossier);
	}

	/**
	 * Supprime le fichier $chemin du disque
	 * @param type $chemin
	 * @return type
	 */
	public function supprimerFichier($chemin) {
		return unlink($chemin);
	}
	
	// $origine est un chemin complet, absolu d'un fichier
	// $dossier_destination est un nom de dossier relatif au "cloud"
	public function stockerFichier($infosFichier, $dossier_destination, $clef) {
		$dossier_destination = $this->preparerCheminFichier($dossier_destination);
		$clef = $this->desinfecterNomFichier($clef);
		// tantantan taaaaan !!!!
		$destination_finale = $dossier_destination . $clef;
		echo $destination_finale . "\n";
		$origine = $infosFichier['tmp_name'];
		$this->deplacerFichierSurDisque($origine, $destination_finale);
	}
	
	// $origine et $destination sont des chemins de fichiers absolus
	protected function deplacerFichierSurDisque($origine, $destination) {
		$deplacement = false;
		if(is_uploaded_file($origine)) {
			$deplacement = move_uploaded_file($origine, $destination);
		} else {
			$deplacement = rename($origine, $destination);	
		}
		
		return $deplacement;
	}	
	
	protected function preparerCheminFichier($dossier_destination) {	
		
		$dossier_destination = $this->desinfecterCheminFichier($dossier_destination);				
		$chemin_dossier_complet = $this->racine_fichiers.$dossier_destination;
		
		if(!is_dir($chemin_dossier_complet)) {
			$ok = mkdir($chemin_dossier_complet, $this->droits, true);
			$chemin_dossier_complet = $ok ? $chemin_dossier_complet : false;		
		}
		
		return $chemin_dossier_complet;
	}
		
	protected function desinfecterCheminFichier($chemin) {
		// pour le moment on supprime les occurences de .. dans les dossiers et les // ou /// etc...
		$chemin = preg_replace("([\.]{2,})", '', $chemin);
		$chemin = preg_replace('/(\/+)/','/', $chemin);
		
		// retire le séparateur de dossier de gauche s'il est présent et s'assure que celui de droite existe
		$chemin = ltrim(rtrim($chemin, $this->ds), $this->ds).$this->ds;
		
		return $chemin;
	}
	
	// http://stackoverflow.com/questions/2021624/string-sanitizer-for-filename
	protected function desinfecterNomFichier($nom) {
		// Remove anything which isn't a word, whitespace, number
		// or any of the following caracters -_~,;:[]().
		$nom = preg_replace("([^\w\s\d\-_~,;:\[\]\(\).])", '', $nom);
		// Remove any runs of periods (thanks falstro!)
		$nom = preg_replace("([\.]{2,})", '', $nom);
		
		return $nom;
	}
} 