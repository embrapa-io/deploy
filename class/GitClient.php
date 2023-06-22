<?php

class GitClient
{
    const GIT = '/usr/bin/git';

    static private $single = FALSE;

    private final function __construct ()
	{
        exec (self::GIT .' config --global user.email "releaser@embrapa.io"');
        exec (self::GIT .' config --global user.name "Embrapa I/O Releaser"');
    }

    static public function singleton ()
	{
		if (self::$single !== FALSE)
			return self::$single;

		$class = __CLASS__;

		self::$single = new $class ();

		return self::$single;
	}

    static public function delete ($path)
    {
        if (!file_exists ($path)) return;

        $files = new RecursiveIteratorIterator (
            new RecursiveDirectoryIterator ($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $fileinfo)
        {
            $todo = ($fileinfo->isDir () ? 'rmdir' : 'unlink');
            $todo ($fileinfo->getRealPath ());
        }

        rmdir ($path);
    }

    public function cloneTag ($project, $app, $stage, $version, $env, $ci, $bk, $path)
    {
        $data = $path . DIRECTORY_SEPARATOR .'data';

        if (!file_exists ($data) || !is_dir ($data))
            throw new Exception ('Volume for data storage is not mounted!');

        chdir ($data);

        $clone = $data . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$project, $app, $version]);

        if (file_exists ($clone)) self::delete ($clone);

        mkdir ($clone, 0777, TRUE);

        exec (self::GIT .' clone --depth 1 --verbose --branch '. $version .' '. getenv ('GITLAB_SSH') .'/'. $project .'/'. $app .'.git '. $clone .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            self::delete ($clone);

            echo "\n". implode ("\n", $output) ."\n";

            throw new Exception ('Impossible to clone repository at tag "'. $version .'"');
        }

        unset ($output);
        unset ($return);

        exec (self::GIT .' config --global --add safe.directory '. $clone .' 2>&1', $output, $return);

        if ($return !== 0)
            echo "\n". implode ("\n", $output) ."\n";

        if (!file_put_contents ($clone . DIRECTORY_SEPARATOR .'.env', $env, LOCK_EX) ||
            !file_put_contents ($clone . DIRECTORY_SEPARATOR .'.env.ci', $ci, LOCK_EX) ||
            !file_put_contents ($clone . DIRECTORY_SEPARATOR .'.env.cli', $bk, LOCK_EX))
        {
            self::delete ($clone);

            throw new Exception ('Impossible to write .env, .env.ci and/or .env.cli files');
        }

        return $clone;
    }
}