These fixtures are transcribed from the approved spec
(`docs/superpowers/specs/2026-09-25-server-ingest-api.md`, §3.6, §3.8, §4.1 and §4.3), the
same files the Node SDK keeps in `test/fixtures/spec/`. The API's own contract fixtures
(`docs/api/v1/fixtures/*.json` in the ClickClacks app repo) are copied into
`tests/fixtures/server/` by `composer fixtures:sync`. `tests/Unit/ContractTest.php`
replays both folders.
