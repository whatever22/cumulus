<?php

/**
 * Classe par défaut pour la gestion des droits - à étendre avec l'adapteur
 * de votre choix
 */
class AuthAdapter {

	/**
	 * doit retourner une représentation de l'utilisateur en cours
	 */
	public function getUser() {
		return null; // gestion des droits désactivée par défaut
	}

	/**
	 * doit retourner l'identifiant de l'utilisateur en cours
	 */
	public function getUserId() {
		return null; // gestion des droits désactivée par défaut
	}

	/**
	 * doit retourner la liste des groupes auxquels appartient l'utilisateur en cours
	 */
	public function getUserGroups() {
		return array(); // gestion des droits désactivée par défaut
	}

	/**
	 * doit retourner true si l'utilisateur en cours est "administrateur", false sinon
	 */
	public function isAdmin() {
		return true; // gestion des droits désactivée par défaut
	}

	/**
	 * jette une exception si l'utilisateur en cours n'est pas "administrateur"
	 */
	public function requireAdmin() {
		if (! $this->isAdmin()) {
			throw new Exception('Vous devez être administrateur pour faire cela');
		}
	}
}