<?php

global $_builds, $_path;

self::singleton ();

$_data = $_path . DIRECTORY_SEPARATOR .'data';

if (!file_exists ($_data) || !is_dir ($_data))
	throw new Exception ('Volume for data storage is not mounted!');

foreach ($_builds as $_build => $_b)
{
	echo "\n";

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name: ". $_build ."! \n";

		continue;
	}

	$version = $_data . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'VERSION'. DIRECTORY_SEPARATOR . $_b->stage;

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

	$clone = DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app, $_last]);

	if (!file_exists ($_data . $clone) || !is_dir ($_data . $clone))
	{
		echo "ERROR > The clone to version/tag '". $_last ."' of build '". $_build ."' is missing! \n";

		continue;
	}

	echo "To ". $_build ." (version ". $_last ."): \ncd data". $clone ." \n";
}

echo "\n";

echo "Once in directory, you can apply commands related to orchestrator ". self::singleton ()->orchestrator .": \n";

echo (self::singleton ()->orchestrator)::reference ();
