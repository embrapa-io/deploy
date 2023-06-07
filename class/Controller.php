<?php

class Controller
{
    static private $single = FALSE;

    const VERSION_REGEX = [
        'alpha' => '/^([\d]+)\.([\d]{2})\.([1-9][0-2]?)-alpha\.([\d]+)$/',
        'beta' => '/^([\d]+)\.([\d]{2})\.([1-9][0-2]?)-beta\.([\d]+)$/',
        'release' => '/^([\d]+)\.([\d]{2})\.([1-9][0-2]?)-([\d]+)$/'
    ];

    const PATH = __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'controller'. DIRECTORY_SEPARATOR;

    private $boilerplates = [];
    private $clusters = null;
    private $types = [];

    private final function __construct ()
	{
        echo "INFO > Trying to load metadata info... \n";

        $git = GitLab::singleton ();

        $load = $git->reposSearch ('io/boilerplate/metadata');

        if (!sizeof ($load))
            throw new Exception ("Repository 'io/boilerplate/metadata' not found!");

        $metadata = $load [0];

        $this->boilerplates = json_decode ($git->getFile ($metadata ['id'], 'boilerplates.json'));

        $this->clusters = json_decode ($git->getFile ($metadata ['id'], 'clusters.json'));

        $this->types = json_decode ($git->getFile ($metadata ['id'], 'orchestrators.json'));

        if (!is_array ($this->boilerplates) || !is_object ($this->clusters) || !is_array ($this->types))
            throw new Exception ("Metadata files not loaded!");

        echo "INFO > Metadata info of boilerplates, clusters and types loaded! \n";
    }

    static public function singleton ()
	{
		if (self::$single !== FALSE)
			return self::$single;

		$class = __CLASS__;

		self::$single = new $class ();

		return self::$single;
	}

    static public function validate ()
    {
        require self::PATH .'validate.php';
    }

    static public function deploy ()
    {
        require self::PATH .'deploy.php';
    }

    static public function health ($_stage)
    {
        require self::PATH .'health.php';
    }

    static public function support ($type, $power)
    {
        require self::PATH .'support/'. $type .'/'. ($power ? 'on' : 'off') .'.php';
    }

    static public function undeploy ()
    {
        require self::PATH .'undeploy.php';
    }

    static public function restart ()
    {
        require self::PATH .'restart.php';
    }

    static protected function score ($stage, $version)
    {
        if (!preg_match (self::VERSION_REGEX [$stage], $version, $matches)) return 0;

        if ((int) $matches [1] > 999) return 0;

        if ((int) $matches [2] < 10 || (int) $matches [2] > 99) return 0;

        if ((int) $matches [3] < 1 || (int) $matches [3] > 12) return 0;

        if ((int) $matches [4] > 9999) return 0;

        return (int) $matches [1] . $matches [2] . str_pad ($matches [3], 2, '0', STR_PAD_LEFT) . str_pad ($matches [4], 4, '0', STR_PAD_LEFT);
    }
}
