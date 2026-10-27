# This is a description for including OpenSpout into Moodle core

## Dependencies

None

## Installation

```sh
mv public/lib/openspout/readme_moodle.txt ./
rm -rf public/lib/openspout/*
installdir=`mktemp -d`
cd "${installdir}"
composer init --require openspout/openspout:^4 -n
composer install
rm -rf vendor/composer
rm vendor/autoload.php
rm vendor/openspout/openspout/renovate.json
rm vendor/openspout/openspout/UPGRADE.md
cd -
cp -rf "${installdir}/vendor/openspout/openspout/"* public/lib/openspout/
mv readme_moodle.txt public/lib/openspout/
rm -rf $installdir
git add .
```
