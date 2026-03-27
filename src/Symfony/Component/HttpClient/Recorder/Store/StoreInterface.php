<?php

namespace Symfony\Component\HttpClient\Recorder\Store;


use Symfony\Component\HttpClient\Har\HarFile;

interface StoreInterface
{
    public function load(string $name): HarFile;

    public function save(string $name, HarFile $har): void;
}
