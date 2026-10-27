# Google APIs Client Library for PHP

This is a description for including the Google APIs Client Library for PHP in Moodle

## Installation

1. Visit https://github.com/googleapis/google-api-php-client
2. Download the latest release
3. Unzip in this folder
4. Update `thirdpartylibs.xml`
5. Remove any unnecessary files and folders, except for the `src` folder and the following files:
 - LICENSE
 - README.md
6. Remove the following files from the `src` folder:
 - src/aliases.php

## Upgrade stack

As of MDL-89576, this library is upgraded together with google2-auth and
google2-service as a coordinated stack: this library requires compatible
versions of the other two, so when bumping this library, check and upgrade
google2-auth and google2-service too.
