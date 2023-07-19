<?php

self::singleton ();

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

foreach ($_builds as $_build => $_b)
{
	echo "\n";

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name: ". $_build ."! \n";

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
		echo "ERROR > No one valid version was deployed to build: ". $_build ."! \n";

		continue;
	}

	$rollback = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'.rollback'. DIRECTORY_SEPARATOR . $_b->stage;

	try
	{
		$_rollback = file_get_contents ($rollback);
	}
	catch (Exception $e)
	{
		$aux = dirname ($rollback);

		if (!file_exists ($aux) || !is_dir ($aux)) mkdir ($aux, 0777, TRUE);

		$_rollback = NULL;
	}

	if (!is_null ($_rollback) && self::score ($_b->stage, $_rollback) && trim ($_rollback) != trim ($_last))
	{
		$_version = $_rollback;
		$isRollbacked = TRUE;
	}
	else
	{
		$_version = $_last;
		$isRollbacked = FALSE;
	}

	$clone = 'apps'. DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app, $_version]);

	if (!file_exists ($_data . DIRECTORY_SEPARATOR . $clone) || !is_dir ($_data . DIRECTORY_SEPARATOR . $clone))
	{
		echo "ERROR > The clone to version/tag '". $_version ."' of build '". $_build ."' is missing! \n";

		continue;
	}

	if ($isRollbacked) echo "Important! This version has rollbacked from version ". $_last .". \n";

	echo "To ". $_build ." (version ". $_version ."): \n";
	echo "cd ". $clone ." \n";
}

echo "\n";

echo "Once in directory, you can apply commands related to orchestrator ". self::singleton ()->orchestrator .": \n";

echo (self::singleton ()->orchestrator)::reference ();
