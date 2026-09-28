# Changelog

## [0.2.0](https://github.com/pietervanleuven/vitodeploy-bunny/compare/v0.1.0...v0.2.0) (2026-09-28)


### Features

* **api:** add timeouts and retries to Bunny HTTP requests ([5543545](https://github.com/pietervanleuven/vitodeploy-bunny/commit/5543545931491cea207a31d24ff4e756c5133fb5))
* **cdn:** store site credentials encrypted or via linked DNS provider ([f1a976c](https://github.com/pietervanleuven/vitodeploy-bunny/commit/f1a976c41ac1e198dfe3a37d145d566f4ec70961))
* **dns:** paginate zone listing ([62a603c](https://github.com/pietervanleuven/vitodeploy-bunny/commit/62a603c64a6c78598265d19703a26bbed0adf97e))
* **dns:** strengthen record validation ([285312f](https://github.com/pietervanleuven/vitodeploy-bunny/commit/285312fa96a9d90f7d89d12194d2557ad065e456))
* harden Bunny services and release automation ([c10bd87](https://github.com/pietervanleuven/vitodeploy-bunny/commit/c10bd87d2db32fd390e7ef09405f5aace5a8919c))
* **storage:** add fail, retry and timeout flags to curl scripts ([5ff3f0a](https://github.com/pietervanleuven/vitodeploy-bunny/commit/5ff3f0adc94a496f37a30fda49341253d4b478bf))


### Bug Fixes

* avoid logging raw API responses and command output ([0595f2b](https://github.com/pietervanleuven/vitodeploy-bunny/commit/0595f2bef961ff210f83882541aeda31d756d127))
* **dns:** handle transport errors in getRecords consistently ([8432d8f](https://github.com/pietervanleuven/vitodeploy-bunny/commit/8432d8fb4a8214ac2906859e1e83f96c57ba91ad))
* **dns:** validate API response shapes ([06c7059](https://github.com/pietervanleuven/vitodeploy-bunny/commit/06c70597c39e43ab8bc6ea2f153613705b3054ef))
* **storage:** delete remote backup files through a BackupFile listener ([01a13ee](https://github.com/pietervanleuven/vitodeploy-bunny/commit/01a13ee2bd3584b1f9a745ab560bc13e512ad492))
* **storage:** escape shell arguments in transfer scripts ([161ebd7](https://github.com/pietervanleuven/vitodeploy-bunny/commit/161ebd79126be9099d8812146220ae80990214c5))
* **storage:** implement the current StorageProvider contract ([aca6ab7](https://github.com/pietervanleuven/vitodeploy-bunny/commit/aca6ab7969b425854d54c7cad229543cd6ef6d06))
* **storage:** percent-encode remote paths instead of replacing characters ([0ba8256](https://github.com/pietervanleuven/vitodeploy-bunny/commit/0ba8256856ff54125a33c641c05e2eda9f008c75))
* **workflow:** scope DNS provider lookup to the workflow user and project ([9b1edc7](https://github.com/pietervanleuven/vitodeploy-bunny/commit/9b1edc727bf589c8806e65b7ef1ee38a46767660))


### Miscellaneous Chores

* add composer manifest and code quality configuration ([8550ac7](https://github.com/pietervanleuven/vitodeploy-bunny/commit/8550ac7c360ef4d37f2544a3eee88901d6223670))
* mark the vendor exclude as optional in phpstan config ([97cc9cd](https://github.com/pietervanleuven/vitodeploy-bunny/commit/97cc9cd3a2bf6ef1bd8a70b8e69e21eaf02733e1))

## Changelog

All notable changes to this project will be documented in this file.
