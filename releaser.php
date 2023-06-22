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
	'ORCHESTRATOR',
	'GITLAB_URL',
	'GITLAB_SSH',
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

@unlink ($_lock);

require_once 'vendor/autoload.php';

require_once 'helper/error.php';

require_once 'class/Log.php';
require_once 'class/Mail.php';
require_once 'class/GitLab.php';
require_once 'class/GitClient.php';
require_once 'class/Controller.php';

require_once 'plugin/Orchestrator.php';
require_once 'plugin/DockerCompose.php';
require_once 'plugin/DockerSwarm.php';

if (!Orchestrator::exists (getenv ('ORCHESTRATOR')))
	die ("CRITICAL > Orchestrator '". getenv ('ORCHESTRATOR') ."' defined in '.env' is not valid! \n");

$_path = getcwd ();

$builds = $_path . DIRECTORY_SEPARATOR .'apps'. DIRECTORY_SEPARATOR .'builds.json';

if (!file_exists ($builds) || !is_readable ($builds))
	die ("CRITICAL > Is needed configure builds of apps to deploy in file '". $builds ."'! \n");

$_builds = json_decode (file_get_contents ($builds));

set_error_handler ('handleError');

try
{
	if (!$_verbose) ob_start ();

	$_benchmark = time ();

	if (!$_verbose) file_put_contents ($_lock, '');

	echo "INFO > Starting execution... \n\n";

	$_nothing = TRUE;

	Controller::singleton ()->deploy ();

	try { @unlink ($_lock); } catch (Exception $e) {}

	echo "FINISH > All done after ". number_format (time () - $_benchmark, 0, ',', '.') ." seconds!";

	if (!$_verbose && !$_nothing) Mail::singleton ()->send ('SUCCESS EXECUTION of Releaser Script', ob_get_clean ());

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

	if (!$_verbose) Mail::singleton ()->send ('CRITICAL ERROR of Releaser Script', ob_get_clean ());
}
catch (Exception $e)
{}
