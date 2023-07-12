<?php

class Operation
{
    private $info = '';
    private $method = '';
    private $params = 0;
    private $usage = '';
    private $example = '';

    public function __construct ($info, $method, $params, $usage, $example)
    {
        $this->info = $info;
        $this->method = $method;
        $this->params = $params;
        $this->usage = $usage;
        $this->example = $example;
    }

    public function __get ($property)
    {
        if (property_exists ($this, $property)) return $this->$property;
    }
}