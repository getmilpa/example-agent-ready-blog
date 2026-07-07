#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\ExampleBlog\App\Demo;
use Milpa\ExampleBlog\App\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$decision = null;
if (\in_array('--auto-approve', $argv, true)) {
    $decision = 'approve';
} elseif (\in_array('--reject', $argv, true)) {
    $decision = 'reject';
}

exit((new Demo(Kernel::boot(), STDIN, STDOUT, $decision))->run());
