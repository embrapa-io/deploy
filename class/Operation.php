<?php

class Operation
{
    private $info = '';
    private $method = '';
    private $params = 0;
    private $usage = '';
    private $examples = [];

    public function __construct ($info, $method, $params, $usage, $examples)
    {
        $this->info = $info;
        $this->method = $method;
        $this->params = intval ($params);
        $this->usage = $usage;
        $this->examples = is_array ($examples) ? $examples : [ $examples ];
    }

    public function __get ($property)
    {
        if (property_exists ($this, $property)) return $this->$property;
    }
}