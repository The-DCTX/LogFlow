-- 002 — Intégrité agents/tokens : la suppression d'une clé API purge ses tokens
-- d'enrôlement (défense en profondeur, en complément de la suppression
-- transactionnelle applicative dans api/agents.php et setup.php).

-- 1) Neutralise d'abord les tokens orphelins (api_key_id pointant vers une clé
--    déjà supprimée) : on les détache et on les révoque pour qu'ils ne puissent
--    pas être réutilisés via le repli « première clé » de download.php.
UPDATE install_tokens t
   LEFT JOIN api_keys k ON t.api_key_id = k.id
   SET t.api_key_id = NULL, t.revoked = 1
 WHERE t.api_key_id IS NOT NULL AND k.id IS NULL;

-- 2) Contrainte d'intégrité : supprimer une clé supprime en cascade ses tokens.
--    (api_key_id reste NULL-able : un token « toutes clés » n'est pas affecté.)
ALTER TABLE install_tokens
  ADD CONSTRAINT fk_token_api_key
  FOREIGN KEY IF NOT EXISTS (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE;
