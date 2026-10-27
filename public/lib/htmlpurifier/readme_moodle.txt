Description of HTML Purifier library import into Moodle

* Make new (or delete contents of) /lib/htmlpurifier/
* Copy everything from /library/ folder to /lib/htmlpurifier/
* Copy CREDITS, LICENSE from root folder to /lib/htmlpurifier/
* Delete unused files:
    HTMLPurifier.auto.php
    HTMLPurifier.autoload.php
    HTMLPurifier.autoload-legacy.php
    HTMLPurifier.composer.php
    HTMLPurifier.func.php
    HTMLPurifier.includes.php
    HTMLPurifier.kses.php
    HTMLPurifier.path.php
* add locallib.php with Moodle specific extensions to /lib/htmlpurifier/
* add this readme_moodle.txt to /lib/htmlpurifier/
* update the ezyang/htmlpurifier version in composer.json to match and run
  `composer update ezyang/htmlpurifier` to keep composer.lock and vendor/
  in sync (Moodle's own autoloader locks HTMLPURIFIER_PREFIX to whichever
  copy loads first, so a mismatched vendor/ copy breaks ConfigSchema lookups)
