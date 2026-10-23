<p align="center">
  <a href="https://tempestphp.com">
    <img src="https://raw.githubusercontent.com/tempestphp/.github/main/logo_current/tempest-logo.png" width="100" />
  </a>
</p>

<h1 align="center">Tempest scaffold</h1>
<div align="center">
  This repository contains the default scaffold for Tempest.
  <br />
  The source code for the framework itself can be found at <code><a href="https://github.com/tempestphp/tempest-framework">tempestphp/tempest-framework</a></code>.
	<br />
	<br />
  <pre><div align="center">composer create-project tempest/app</div></pre>
	<br />
	<br />
	<sub>
		Check out the <a href="https://tempestphp.com">documentation</a>
		&nbsp;
		·
		&nbsp;
		Join the <a href="https://tempestphp.com/discord">Discord</a> server
  </sub>
</div>

## Local Vault browser

Open the existing Tempest application through Lerd to browse canonical Items,
select historical revisions and download their exact preserved Asset bytes.
See [launch instructions, limitations and running screenshots](docs/vault-browser/README.md).

Filesystem Input/Broadcast configuration is available through the CLI; see
[setup, safety rules and milestone limits](docs/filesystem-round-trip.md).

## Tests

Run tests through the repository wrapper, which selects the Lerd or CI runtime
and uses four parallel Pest workers by default:

```sh
./bin/test.sh
./bin/test.sh tests/Acceptance/RecordsAndArchives
```

Set `STASHD_TEST_PROCESSES` to change the worker count. Use `1` for sequential
Pest execution:

```sh
STASHD_TEST_PROCESSES=1 ./bin/test.sh tests/Acceptance/RecordsAndArchives
```
