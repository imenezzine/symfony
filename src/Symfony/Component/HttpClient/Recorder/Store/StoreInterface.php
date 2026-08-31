<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Recorder\Store;

use Symfony\Component\HttpClient\Har\HarFile;

interface StoreInterface
{
    public function load(string $name): HarFile;

    public function save(string $name, HarFile $har): void;
}
