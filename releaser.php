<?php
/**
 * embrapa.io/releaser
 * Script for automatic deploy of Embrapa I/O apps releases in external remote environments.
 *
 * Copyright 2023: Brazilian Agricultural Research Corporation - Embrapa
 *
 * @author Camilo Carromeu <camilo.carromeu@embrapa.br>
 *
 * Bootstrap script.
 */

error_reporting (E_ALL);
set_time_limit (0);
ini_set ('memory_limit', '-1');
ini_set ('register_argc_argv', '1');

if (PHP_SAPI != 'cli')
	die ("CRITICAL > This is a command-line script! You cannot call by browser. \n");

if (!`which git`)
	die ("CRITICAL > GIT package not found!");

if (!(int) ini_get ('register_argc_argv'))
	die ("CRITICAL > This is a command-line script! You must enable 'register_argc_argv' directive. \n");

$vars = [
	'SERVER',
	'GITLAB_URL',
	'GITLAB_TOKEN',
	'SMTP_HOST',
	'SMTP_PORT',
	'SMTP_SECURE',
	'LOG_MAIL'
];

$unsetted = [];

foreach ($vars as $trash => $var)
	if (getenv ($var) === FALSE)
		$unsetted [] = $var;

if (sizeof ($unsetted))
	die ("CRITICAL > Required environment variables are not setted: ". implode (', ', $unsetted) ."! \n");

$_verbose = (bool) getenv ('VERBOSE');

$_lock = '/app/data/.lock';

if (!$_verbose && file_exists ($_lock) && (!is_writable ($_lock) || time () - filemtime ($_lock) < getenv ('LOCK_LIFETIME_MINUTES') * 60))
	die ("CRITICAL > The script is already being performed by a process started earlier! \n");

unlink ($_lock);

$builds = '/app/apps/builds.json';

if (!file_exists ($builds) || !is_readable ($builds))
	die ("CRITICAL > Is needed configure builds of apps to deploy in file 'apps/builds.json'! \n");

$_builds = json_decode (file_get_contents ($builds));

require_once 'vendor/autoload.php';

require 'helper/error.php';

require 'class/GitLab.php';
require 'class/GitClient.php';
require 'class/Log.php';
require 'class/Mail.php';

$_path = getcwd ();

set_error_handler ('handleError');

try
{
	if (!$_verbose) ob_start ();

	$_benchmark = time ();

	file_put_contents ($_lock, '');

	echo "INFO > Starting execution... \n\n";

	$_nothing = TRUE;

	foreach ($_builds as $trash => $b)
	{
		echo "INFO > Trying to load info for '". $b->project ."/". $b->app ."@". $b->stage ."'... \n";
	}

	unlink ($_lock);

	echo "FINISH > All done after ". number_format (time () - $_benchmark, 0, ',', '.') ." seconds!";

	if (!$_verbose && !$_nothing) Log::info (ob_get_clean ());

	exit (0);
}
catch (Exception $e)
{
	echo "\n";

	echo "CRITICAL > ". $e->getMessage () ."\n\n";
}

try
{
	echo "FINISH > Stopped after ". number_format (time () - $_benchmark, 0, ',', '.') ." seconds!";

	if (!$_verbose) Log::critical (ob_get_clean ());
}
catch (Exception $e)
{}
