<?php

namespace tests;

/**
 * Copyright (c) 2004-2022 Roman Ožana (https://ozana.cz)
 *
 * @license BSD-3-Clause
 * @author Roman Ožana <roman@ozana.cz>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Closure;
use Tester\Environment;

function test($description, Closure $fn) {
	printf("• %s%s%s", $description, PHP_EOL, $fn());
}

Environment::setup();