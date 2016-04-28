# Cumulus
Un système de stockage de fichiers du genre "cloud" avec API REST

## Documentation
[autodoc.json](autodoc.json)

## Installation
```
composer install
```

## Configuration
Créer les fichiers config/config.json et config/service.json

## Adapteurs
Vous pouvez écrire vos propres adapteurs de stockage et d'authentification

### Stockage
Un adapteur de stockage pour MySQL est livré par défaut : stockagetb. Utiliser
install/create_table.sql et install/example_data.sql pour amorcer la base de
données

Les addapteurs de stockage doivent implémenter CumulusInterface

### Authentification
Un adapteur d'authentification pour Tela Botanica est livré par défaut :
authproxytb.

Si aucun adapteur d'authentification n'est mentionné dans la configuration,
les utilisateurs ont tous les droits sur tout.

Les adapteurs d'authentification doivent étendre AuthAdapter