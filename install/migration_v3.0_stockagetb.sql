-- script de migration du modèle de stockage "stockagetb" de la version 2 à la version 3

ALTER TABLE cumulus_files ADD COLUMN hidden boolean NOT NULL DEFAULT false;
