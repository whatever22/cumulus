<?php

/**
 * Authentification / gestion des utilisateurs à l'aide du SSO de Tela Botanica
 */
class AuthProxyTB extends AuthAdapter {

	protected $lib;
	protected $sso;

	/** $lib est l'objet appelant (classe Cumulus) */
	public function __construct($lib=null, $config=null) {
		$this->lib = $lib;
		$this->sso = new AuthTB($config); // Composer lib
	}

	/**
	 * Retourne les données utilisateur en cours
	 */
	public function getUser() {
		return $this->sso->getUser();
	}

	/**
	 * Retourne l'identifiant de l'utilisateur (id numérique)
	 */
	public function getUserId() {
		return $this->sso->getUserId();
	}

	/**
	 * Retourne les groupes auxquels l'utilisateur en cours appartient
	 */
	public function getUserGroups() {
		return $this->sso->getUserGroups();
	}

	/**
	 * Retourne true si le *courriel* de l'utilisateur identifié par le jeton
	 * SSO est dans la liste des admins, située dans la configuration
	 */
	public function isAdmin() {
		return $this->sso->isAdmin();
	}
}