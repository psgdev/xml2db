This library is not tend to be used outside application and environment where it had been built. However, you can use it without warranty of any kind.

Requires musqlidb from psgdev (added to plugin's composer.json)

How to install

"require": {
"psgdev/xml2db": "dev-master"
},
"repositories": [
    {
        "type": "git",
        "url":  "https://github.com/psgdev/xml2db.git"
    }
],



- add this line to app.php, providers section
Psgdev\Xml2db\Xml2dbServiceProvider::class

- publish this vendor to copy config.php file from Config dir

Create console command when writing an xml parser using this plugin.

