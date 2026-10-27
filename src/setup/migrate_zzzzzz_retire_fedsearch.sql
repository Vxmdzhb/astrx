-- The federated search (module fedsearch) is gone: its tabs are now part of
-- the site's one Search page, where the engine modules add them. On a site
-- that had it installed, remove its page and the navbar link to it; the
-- module's own teardown can't run, because the module no longer ships.

DELETE FROM `page` WHERE `module` = 'fedsearch';   -- cascades to meta/robots/closure/navbar_internal
DELETE nid FROM `navbar_entry_ids` nid
  JOIN `navbar_entry` ne ON ne.id = nid.id
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
DELETE nid FROM `navbar_entry_ids` nid
  LEFT JOIN `navbar_entry` ne ON ne.id = nid.id
 WHERE ne.id IS NULL;
