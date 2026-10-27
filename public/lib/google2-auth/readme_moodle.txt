# Google Auth Library for PHP

This is a description for including the Google Auth Library for PHP in Moodle

## Installation

1. Visit https://github.com/googleapis/google-auth-library-php
2. Download the latest release
3. Unzip in this folder
4. Update `thirdpartylibs.xml`
5. Remove any unnecessary files and folders, except for the `src` folder and the following files:
 - composer.json
 - LICENSE
 - README.md

## Upgrade stack

As of MDL-89576, this library is upgraded together with google2 and
google2-service as a coordinated stack: google2 (the top-level client)
requires a compatible version of this library, so when bumping this
library, check and upgrade google2 and google2-service too.
