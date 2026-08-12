-- ============================================================================
-- Migration: decode the HTML entities left in stored text by 7.0.
--
-- Until 7.1, Contact::set() and Domain::set() ran every value through
-- htmlspecialchars() before storing it, so the database holds HTML entities
-- rather than the characters themselves: an organisation named
--
--     Rossi & Figli S.r.l.
--
-- is stored as
--
--     Rossi &amp; Figli S.r.l.
--
-- and comes back that way from every read -- the REST API, CSV exports, and
-- anything reading the tables directly. It was also the wrong escaping for the
-- job: the values are sent to the registry as XML, which 7.1 now escapes at
-- serialization, exactly once and correctly.
--
-- This decodes what is there. Safe to run on data that has none: every
-- REPLACE() is then a no-op.
--
-- IMPORTANT:
--   * Take a backup first, as with any migration in this chain.
--   * Order matters. &amp; is decoded LAST: doing it first would turn a
--     literal "&amp;lt;" -- someone who really typed "&lt;" -- into "<".
--     Decoding the others first and & last is the exact inverse of one
--     htmlspecialchars() pass, which is what was applied.
--   * ENT_COMPAT was used, which encodes & < > and " but NOT the single
--     quote, so there is no &#039; to undo.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- PART 1: PRE-FLIGHT -- report what will change
--
-- Read-only. Run the script and read this before deciding to keep the result;
-- it lists every row the decode below will touch.
-- ----------------------------------------------------------------------------

SELECT 'contacts rows to decode' AS report, COUNT(*) AS n
FROM contacts
WHERE name        LIKE '%&amp;%' OR name        LIKE '%&lt;%' OR name        LIKE '%&gt;%' OR name        LIKE '%&quot;%'
   OR org         LIKE '%&amp;%' OR org         LIKE '%&lt;%' OR org         LIKE '%&gt;%' OR org         LIKE '%&quot;%'
   OR street      LIKE '%&amp;%' OR street      LIKE '%&lt;%' OR street      LIKE '%&gt;%' OR street      LIKE '%&quot;%'
   OR street2     LIKE '%&amp;%' OR street2     LIKE '%&lt;%' OR street2     LIKE '%&gt;%' OR street2     LIKE '%&quot;%'
   OR street3     LIKE '%&amp;%' OR street3     LIKE '%&lt;%' OR street3     LIKE '%&gt;%' OR street3     LIKE '%&quot;%'
   OR city        LIKE '%&amp;%' OR city        LIKE '%&lt;%' OR city        LIKE '%&gt;%' OR city        LIKE '%&quot;%'
   OR province    LIKE '%&amp;%'
   OR email       LIKE '%&amp;%'
   OR voice       LIKE '%&amp;%'
   OR fax         LIKE '%&amp;%'
   OR regcode     LIKE '%&amp;%'
   OR schoolcode  LIKE '%&amp;%';


-- ----------------------------------------------------------------------------
-- PART 2: DECODE
--
-- Every text column a caller could have set through Contact::set() or
-- Domain::set(). Columns the code never routes through set() -- ids,
-- timestamps, serialized blobs -- are left alone.
-- ----------------------------------------------------------------------------

UPDATE contacts SET
  name       = REPLACE(REPLACE(REPLACE(REPLACE(name,       '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  org        = REPLACE(REPLACE(REPLACE(REPLACE(org,        '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  street     = REPLACE(REPLACE(REPLACE(REPLACE(street,     '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  street2    = REPLACE(REPLACE(REPLACE(REPLACE(street2,    '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  street3    = REPLACE(REPLACE(REPLACE(REPLACE(street3,    '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  city       = REPLACE(REPLACE(REPLACE(REPLACE(city,       '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  province   = REPLACE(REPLACE(REPLACE(REPLACE(province,   '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  postalcode = REPLACE(REPLACE(REPLACE(REPLACE(postalcode, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  voice      = REPLACE(REPLACE(REPLACE(REPLACE(voice,      '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  fax        = REPLACE(REPLACE(REPLACE(REPLACE(fax,        '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  email      = REPLACE(REPLACE(REPLACE(REPLACE(email,      '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  authinfo   = REPLACE(REPLACE(REPLACE(REPLACE(authinfo,   '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  regcode    = REPLACE(REPLACE(REPLACE(REPLACE(regcode,    '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  schoolcode = REPLACE(REPLACE(REPLACE(REPLACE(schoolcode, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&');

UPDATE domains SET
  authinfo   = REPLACE(REPLACE(REPLACE(REPLACE(authinfo,   '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&');


-- ----------------------------------------------------------------------------
-- PART 3: VERIFY -- expect ZERO rows
-- ----------------------------------------------------------------------------

SELECT handle, name, org
FROM contacts
WHERE name LIKE '%&amp;%' OR org LIKE '%&amp;%' OR street LIKE '%&amp;%' OR city LIKE '%&amp;%';
-- ^ any row here still holds an entity, which means it was double-encoded more
--   than once. Decode it again by hand after checking what it should read.


-- ----------------------------------------------------------------------------
-- PART 4: SCHEMA VERSION STAMP
-- ----------------------------------------------------------------------------

REPLACE INTO settings (`key`, `value`) VALUES ('schema_version', '"070100"');
