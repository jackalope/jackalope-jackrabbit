Changelog
=========

1.1.0
-----

* Performance improvements: The fetchDepth feature is now fully supported.
* Support for logging PHPCR database queries.
* mix:lastModified fields can be handled automatically.
* Commands cleanup, check cli-config.php.dist.

1.1.0-RC1
---------

* **2014-01-04**: mix:lastModified is now handled automatically. To disable,
  set the option jackalope.auto_lastmodified to `false`.

* **2013-12-26**: cleanup of phpcr-utils lead to adjust cli-config.php.dist.
  If you use the console, you need to sync your cli-config.php file with the
  dist file.

* **2013-12-14**: Added support for logging PHPCR database queries.
