<?php

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

echo "INFO > Checking status of ". sizeof ($_builds) ." build(s) to RESTART... \n";

foreach ($_builds as $_build => $_b)
{
	echo "\n";

	echo "=== ". $_build ." === \n\n";

	echo "INFO > Checking if build '". $_build ."' has has been deployed... \n";

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name! \n\n";

		continue;
	}

	$version = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'.version'. DIRECTORY_SEPARATOR . $_b->stage;

	try
	{
		$_last = file_get_contents ($version);
	}
	catch (Exception $e)
	{
		$_last = NULL;
	}

	if (!is_string ($_last) || trim ($_last) == '' || !self::score ($_b->stage, $_last))
	{
		echo "ERROR > No one valid version was deployed to this build! \n\n";

		continue;
	}

	$clone = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app, $_last]);

	if (!file_exists ($clone) || !is_dir ($clone))
	{
		echo "ERROR > The clone to version/tag '". $_last ."' is missing! \n\n";

		continue;
	}

	echo "INFO > Restarting version '". $_last ."'... \n";

	try
	{
		(self::singleton ()->orchestrator)::restart ($clone);
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossibe to restart build. ". $e->getMessage () ."! \n\n";

		continue;
	}

	echo "SUCCESS > All done! The version '". $_last ."' of build '". $_build ."' was successfully restarted! \n";
}
